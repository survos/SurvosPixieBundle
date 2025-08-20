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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:injest', 'Import JSON/NDJSON/CSV into Rows (file or directory).')]
final class PixieInjestCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly JsonIngestor $json,
        private readonly ?CsvIngestor $csv,
        private readonly DtoMapper $dtoMapper,           // REQUIRED now
        private readonly ?DtoRegistry $dtoRegistry,
        private readonly StatsCollector $statsCollector,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pixie code (e.g., larco, immigration)')]
        string $pixieCode,
        #[Option(name: 'file',         description: 'Path to a JSON/JSONL/CSV file')] ?string $file = null,
        #[Option(name: 'dir',          description: 'Directory with *.json, *.jsonl, *.csv')] ?string $dir = null,
        #[Option(name: 'core',         description: 'Core code to import into')] string $core = 'obj',
        #[Option(name: 'pk',           description: 'Primary key in the source record')] string $pk = 'id',
        #[Option(name: 'label-field',  description: 'Fallback label field in the raw source')] ?string $labelField = null,
        #[Option(name: 'dto',          description: 'DTO FQCN to normalize rows (overrides auto)')] ?string $dto = null,
        #[Option(name: 'dto-auto',     description: 'Auto-select DTO from registry (Mapper priority/when/except)')] ?bool $dtoAuto = null,
        #[Option(name: 'batch',        description: 'Flush batch size')] int $batch = 1000,
        #[Option(name: 'limit',        description: 'Max records per file (0 = all)')] int $limit = 0,
        #[Option(name: 'debug',        description: 'Print a before/after sample row for mapping')] bool $debug = false,
        // Back-compat alias:
        #[Option(name: 'dto-class',    description: 'Alias of --dto (back-compat)')] ?string $dtoClass = null,
    ): int {
        $dto = $dto ?? $dtoClass;

        // default to auto if neither provided
        if ($dto === null && $dtoAuto === null) {
            $dtoAuto = true;
        }

        if ($dto && !\class_exists($dto)) {
            $io->error("DTO class not found: $dto");
            return 1;
        }

        $ctx = $this->pixie->getReference($pixieCode);
        $ownerRef = $ctx->ownerRef ?? $ctx->em->getReference(Owner::class, $pixieCode);
        $em  = $ctx->em;

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

        $io->title(sprintf('Injest %s core=%s pk=%s dto=%s auto=%s',
            $pixieCode, $core, $pk, $dto ?? 'none', $dtoAuto ? 'yes' : 'no'
        ));

        // ensure Core exists and capture ids to reattach after clear()
        $coreEntity = $this->pixie->getCore($core, $ownerRef);
        $coreId  = $coreEntity->id ?? $coreEntity->getId();
        $ownerId = $pixieCode;

        $seen = []; $total = 0; $unchanged = 0; $printedDebug = false;

        foreach ($files as $path) {
            $i = 0;
            $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            $io->section(basename($path));

            $iter = match ($ext) {
                'json','jsonl' => $this->json->iterate($path),
                'csv' => $this->requireCsv()->iterate($path, null),
                default => null
            };
            if (!$iter) { $io->warning("Skipping unsupported file: $path"); continue; }

            foreach ($iter as $record) {
                if (!\is_array($record)) continue;

                $idWithinCore = (string)($record[$pk] ?? '');
                if ($idWithinCore === '' || isset($seen[$idWithinCore])) continue;
                $seen[$idWithinCore] = true;

                // choose DTO
                $chosen = $dto;
                if (!$chosen && $dtoAuto && $this->dtoRegistry) {
                    $sel = $this->dtoRegistry->select($pixieCode, $core, $record);
                    $chosen = $sel['class'] ?? null;
                }

                // map
                // choose DTO(s)
                $normalized = $record;

                if ($dto) {
                    // explicit single DTO
                    $dtoObj     = $this->dtoMapper->mapRecord($record, $dto, ['pixie'=>$pixieCode,'core'=>$core]);
                    $normalized = $this->dtoMapper->toArray($dtoObj);
                } elseif ($dtoAuto && $this->dtoRegistry) {
                    // merge from best → fallback
                    $ranked = $this->dtoRegistry->rank($pixieCode, $core, $record);
                    if ($debug && $ranked) {
//                        $io->writeln('DTO order: ' . implode(' > ', array_map(fn($r) => $r['class'].'('.$r['score'].')', $ranked)));
                    }

                    $normalized = []; // start empty for a clean merge
                    foreach ($ranked as $r) {
                        try {
                            $dtoObj = $this->dtoMapper->mapRecord($record, $r['class'], ['pixie'=>$pixieCode,'core'=>$core]);
                            $arr    = $this->dtoMapper->toArray($dtoObj);
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


//                dd($chosen, $this->dtoRegistry, $dtoAuto);
                if ($chosen) {
                    try {
                        $dtoObj    = $this->dtoMapper->mapRecord($record, $chosen, ['pixie'=>$pixieCode,'core'=>$core]);
                        $normalized = $this->dtoMapper->toArray($dtoObj);
                        $this->statsCollector->accumulate($pixieCode, $core, $normalized, $chosen);
                    } catch (\Throwable $e) {
                        $io->warning("DTO mapping failed for id=$idWithinCore: ".$e->getMessage());
                    }
                }

                if ($normalized === $record) {
                    $unchanged++;
                    if ($debug && !$printedDebug) {
                        $io->note("Mapping produced no changes for id=$idWithinCore (DTO=".($chosen ?? 'none').")");
                        $io->writeln('RAW:  '.json_encode($record, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $io->writeln('NORM: '.json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $printedDebug = true;
                    }
                } elseif ($debug && !$printedDebug) {
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
                    $ownerRef   = $em->getReference(Owner::class, $ownerId);
                    $coreEntity = $em->getReference(Core::class,  $coreId);
                    $ctx->ownerRef = $ownerRef;
                    $this->statsCollector->flush($em);

                }
                if ($limit && $i >= $limit) break;
            }

            $em->flush();
            $total += $i;
            $io->writeln("Imported $i from " . basename($path));
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
}
