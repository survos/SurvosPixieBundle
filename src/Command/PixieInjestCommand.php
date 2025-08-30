<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Import\Ingest\JsonIngestor;
use Survos\PixieBundle\Import\Ingest\CsvIngestor;
use Survos\PixieBundle\Service\DtoMapper;
use Survos\PixieBundle\Service\DtoRegistry;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\StatsCollector;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:injest', 'Import JSON/NDJSON/CSV into Rows (file or directory).')]
final class PixieInjestCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly JsonIngestor $json,            // ADDED: Missing dependency
        private readonly ?CsvIngestor $csv,
        private readonly DtoMapper $dtoMapper,
        private readonly ?DtoRegistry $dtoRegistry,
        private readonly StatsCollector $statsCollector,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code (e.g., larco, immigration)')]
        ?string $pixieCode=null,
        #[Option('Path to a JSON/JSONL/CSV file')]
        ?string $file = null,
        #[Option('Directory with *.json, *.jsonl, *.csv')]
        ?string $dir = null,
        #[Option('Core code to import into')]
        string $core = 'obj',
        #[Option('Primary key in the source record')]
        string $pk = 'id',
        #[Option(name: 'label-field', description: 'Fallback label field in the raw source')]
        ?string $labelField = null,
        #[Option('DTO FQCN to normalize rows (overrides auto)')]
        ?string $dto = null,
        #[Option(name: 'dto-auto', description: 'Auto-select DTO from registry')]
        bool $dtoAuto = true,
        #[Option('Flush batch size')]
        int $batch = 1000,
        #[Option('Max records per file (0 = all)')]
        ?int $limit = null,
        #[Option('Print a before/after sample row for mapping')]
        ?bool $dump = null,
        #[Option(name: 'dto-class', description: 'Alias of --dto (back-compat)')]
        ?string $dtoClass = null,
    ): int {

        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            $io->error("Pass in pixieCode or set PIXIE_CODE env var");
            return Command::FAILURE;
        }

        $isDev = $this->env === 'dev';
        $limit ??= $isDev ? 50 : 0;
        $dto = $dto ?? $dtoClass;

        // Get pixie context and config first
        $ctx = $this->pixie->getReference($pixieCode);
        $config = $ctx->config;

        // Auto-detect primary key from config rules
        $actualPk = $this->determinePrimaryKey($config, $core, $pk);
        if ($actualPk !== $pk) {
            $io->writeln("Primary key detected from config: '$pk' -> '$actualPk'");
            $pk = $actualPk;
        }

        // default to auto if neither provided
        if ($dto === null && $dtoAuto === null) {
            $dtoAuto = true;
        }

        if ($dto && !\class_exists($dto)) {
            $io->error("DTO class not found: $dto");
            return 1;
        }

        $ownerRef = $ctx->ownerRef ?? $ctx->em->getReference(Owner::class, $pixieCode);
        $em = $ctx->em;

        // resolve sources
        $files = [];
        if ($file) {
            $files = [$file];
        } else {
            $dir ??= getcwd()."/data/$pixieCode/json";
            if (!is_dir($dir)) {
                $io->error("Directory not found: $dir (pass --file or --dir)");
                return 1;
            }
            $files = array_merge(
                glob(rtrim($dir,'/').'/*.json') ?: [],
                glob(rtrim($dir,'/').'/*.jsonl')?: [],
                glob(rtrim($dir,'/').'/*.csv')  ?: []
            );
            if (!$files) {
                $io->warning("No *.json/*.jsonl/*.csv in $dir");
                return 0;
            }
        }

        // Filter out metadata files
        $files = array_filter($files, fn($f) => basename($f) !== '_files.json');

        $io->title(sprintf('Injest %s core=%s pk=%s dto=%s auto=%s',
            $pixieCode, $core, $pk, $dto ?? 'none', $dtoAuto ? 'yes' : 'no'
        ));

        // ensure Core exists and capture ids to reattach after clear()
        $coreEntity = $this->pixie->getCore($core, $ownerRef);
        $coreId = $coreEntity->id ?? $coreEntity->getId();
        $ownerId = $pixieCode;

        $seen = []; $total = 0; $unchanged = 0; $printedDebug = false;

        foreach ($files as $path) {
            $i = 0;
            $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            $io->section(basename($path));

            // Debug: Check if file is readable and valid
            if (!is_readable($path)) {
                $io->error("File not readable: $path");
                continue;
            }

            $fileSize = filesize($path);
            $io->writeln("File size: " . number_format($fileSize) . " bytes");

            if ($fileSize === 0) {
                $io->warning("Empty file, skipping: $path");
                continue;
            }

            // Check JSON validity for JSON files
            if ($ext === 'json') {
                $content = file_get_contents($path);
                if (empty(trim($content))) {
                    $io->warning("Empty JSON file, skipping: $path");
                    continue;
                }

                // Check if it starts with valid JSON
                $firstChar = trim($content)[0] ?? '';
                if ($firstChar !== '[' && $firstChar !== '{') {
                    $io->error("Invalid JSON file (doesn't start with [ or {): $path");
                    $io->writeln("First 100 chars: " . substr($content, 0, 100));
                    continue;
                }
            }

            try {
                $iter = match ($ext) {
                    'json','jsonl' => $this->json->iterate($path),
                    'csv' => $this->requireCsv()->iterate($path, null),
                    default => null
                };

                if (!$iter) {
                    $io->warning("Skipping unsupported file: $path");
                    continue;
                }
            } catch (\Throwable $e) {
                $io->error("Failed to create iterator for $path: " . $e->getMessage());
                continue;
            }

            $meta = $this->readCountsMetadataFor($path);
            $target = $this->guessTargetCount($path, $meta);

            $pb = $io->createProgressBar($target ?: 0);
            $pb->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
            $pb->setMessage(\basename($path));
            $pb->start();

            $recordCount = 0;
            $skippedCount = 0;

            foreach ($iter as $record) {
                $recordCount++;

                if (!\is_array($record)) {
                    $skippedCount++;
                    if ($recordCount <= 5) {
                        $io->writeln("\nSkipping non-array record #$recordCount: " . gettype($record));
                    }
                    continue;
                }

                $idWithinCore = (string)($record[$pk] ?? '');
                if ($idWithinCore === '') {
                    $skippedCount++;
                    if ($recordCount <= 5) {
                        $io->writeln("\nSkipping record #$recordCount - missing pk '$pk'");
                        $io->writeln("Available keys: " . implode(', ', array_keys($record)));
                    }
                    continue;
                }

                if (isset($seen[$idWithinCore])) {
                    $skippedCount++;
                    continue;
                }

                $seen[$idWithinCore] = true;

                // choose DTO
                $chosen = $dto;
                if (!$chosen && $dtoAuto && $this->dtoRegistry) {
                    $sel = $this->dtoRegistry->select($pixieCode, $core, $record);
                    $chosen = $sel['class'] ?? null;
                }

                // Apply DTO mapping
                $normalized = $record;

                if ($dto) {
                    // explicit single DTO
                    try {
                        $dtoObj = $this->dtoMapper->mapRecord($record, $dto, ['pixie'=>$pixieCode,'core'=>$core]);
                        $normalized = $this->dtoMapper->toArray($dtoObj);
                    } catch (\Throwable $e) {
                        $io->writeln("\nDTO mapping failed for explicit DTO: " . $e->getMessage());
                    }
                } elseif ($dtoAuto && $this->dtoRegistry) {
                    // merge from best → fallback
                    $ranked = $this->dtoRegistry->rank($pixieCode, $core, $record);
                    if ($dump && $ranked && $i < 3) {
                        $io->writeln('\nDTO order: ' . implode(' > ', array_map(fn($r) => $r['class'].'('.$r['score'].')', $ranked)));
                    }

                    $normalized = []; // start empty for a clean merge
                    foreach ($ranked as $r) {
                        try {
                            $dtoObj = $this->dtoMapper->mapRecord($record, $r['class'], ['pixie'=>$pixieCode,'core'=>$core]);
                            $arr = $this->dtoMapper->toArray($dtoObj);
                            // merge: only fill missing/null keys
                            foreach ($arr as $k => $v) {
                                if ($v === null) continue;
                                if (!array_key_exists($k, $normalized) || $normalized[$k] === null) {
                                    $normalized[$k] = $v;
                                }
                            }
                        } catch (\Throwable $e) {
                            $io->warning("DTO mapping failed for {$r['class']}: ".$e->getMessage());
                        }
                    }
                    // if nothing filled, fall back to raw record
                    if ($normalized === []) {
                        $normalized = $record;
                    }
                }

                if ($chosen) {
                    try {
                        $dtoObj = $this->dtoMapper->mapRecord($record, $chosen, ['pixie'=>$pixieCode,'core'=>$core]);
                        $normalized = $this->dtoMapper->toArray($dtoObj);
                        $this->statsCollector->accumulate($pixieCode, $core, $normalized, $chosen);
                    } catch (\Throwable $e) {
                        $io->warning("DTO mapping failed for id=$idWithinCore: ".$e->getMessage());
                    }
                }

                if ($normalized === $record) {
                    $unchanged++;
                    if ($dump && !$printedDebug) {
                        $io->note("Mapping produced no changes for id=$idWithinCore (DTO=".($chosen ?? 'none').")");
                        $io->writeln('RAW:  '.json_encode($record, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $io->writeln('NORM: '.json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $printedDebug = true;
                    }
                } elseif ($dump && !$printedDebug) {
                    $io->success("Mapped id=$idWithinCore with DTO ".($chosen ?? 'none'));
                    $io->writeln('RAW:  '.json_encode($record, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $io->writeln('NORM: '.json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $printedDebug = true;
                }

                // upsert row
                $rowId = Row::RowIdentifier($coreEntity, $idWithinCore);
                /** @var Row|null $row */
                if (!$row = $ctx->repo(Row::class)->find($rowId)) {
                    $row = new Row($coreEntity, $idWithinCore);
                    $em->persist($row);
                }

                // label
                $label = $normalized['label'] ?? ($normalized['name'] ?? ($normalized['title'] ?? null));
                if (!$label && $labelField && isset($record[$labelField])) {
                    $label = (string)$record[$labelField];
                }
                $row->setLabel($label ?: ("row $core:$idWithinCore"));

                // store raw + normalized
                $row->raw = $record;
                $row->setData($normalized);

                if ((++$i % $batch) === 0) {
                    $em->flush();
                    $em->clear(); // ORM 3: full clear; reattach
                    // reattach managed refs
                    $ownerRef = $em->getReference(Owner::class, $ownerId);
                    $coreEntity = $em->getReference(Core::class, $coreId);
                    $ctx->ownerRef = $ownerRef;
                    $this->statsCollector->flush($em);
                }
                if ($limit && $i >= $limit) break;
                $pb->advance();
            }

            $pb->finish();
            $io->newLine(2);

            $em->flush();
            $total += $i;
            $io->writeln("Processed $recordCount records, imported $i, skipped $skippedCount from " . basename($path));
        }

        if ($unchanged > 0) {
            $io->note("$unchanged record(s) were unchanged by mapping (still raw keys). Did you pass --dto or enable --dto-auto?");
        }

        $io->success("Done. Imported $total rows to core=$core.");
        return 0;
    }

    private function requireCsv(): CsvIngestor
    {
        if (!$this->csv) {
            throw new \RuntimeException('CsvIngestor not available. Install league/csv and wire CsvIngestor.');
        }
        return $this->csv;
    }

    /** Read counts from _files.json in the directory containing $file */
    private function readCountsMetadataFor(string $file): array
    {
        $dir = \dirname($file);
        $metaFile = $dir.'/_files.json';
        if (!is_file($metaFile)) return [];
        try {
            $json = json_decode((string) file_get_contents($metaFile), true, 512, JSON_THROW_ON_ERROR);
            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Try to get a target row-count for a specific file from its _files.json */
    private function guessTargetCount(string $file, array $meta): int
    {
        $base = \basename($file);
        return (int)($meta[$base] ?? 0);
    }

    /**
     * Determine the actual primary key field from the pixie configuration rules.
     * Looks for a rule that maps some source field to 'id'.
     */
    private function determinePrimaryKey($config, string $core, string $fallbackPk): string
    {
        try {
            if (!method_exists($config, 'getTables')) {
                return $fallbackPk;
            }

            $tables = $config->getTables();
            if (!isset($tables[$core])) {
                return $fallbackPk;
            }

            $table = $tables[$core];

            // Get rules - this might be a method call or property access
            $rules = null;
            if (method_exists($table, 'getRules')) {
                $rules = $table->getRules();
            } elseif (property_exists($table, 'rules')) {
                $rules = $table->rules;
            } elseif (is_array($table) && isset($table['rules'])) {
                $rules = $table['rules'];
            }

            if (!$rules) {
                return $fallbackPk;
            }

            // Look through rules to find what maps to 'id'
            foreach ($rules as $sourceField => $targetField) {
                // Handle different rule formats:
                // '/url_id/: id' becomes 'url_id' -> 'id'
                $cleanSourceField = trim($sourceField, '/');
                $cleanTargetField = trim($targetField);

                if ($cleanTargetField === 'id') {
                    return $cleanSourceField;
                }
            }

            return $fallbackPk;

        } catch (\Throwable $e) {
            // If anything goes wrong, just return the fallback
            return $fallbackPk;
        }
    }
}