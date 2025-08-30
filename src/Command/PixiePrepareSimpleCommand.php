<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Import\Ingest\CsvIngestor;
use Survos\PixieBundle\Import\Ingest\JsonIngestor;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand('pixie:prepare', 'Convert raw files to JSON with minimal processing')]
final class PixiePrepareSimpleCommand
{
    private bool $initialized = false;
    private ?ProgressBar $progressBar = null;
    private int $total = 0;
    private array $openFiles = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ParameterBagInterface $bag,
        private readonly PixieService $pixie,
        private readonly JsonIngestor $json,
        private readonly ?CsvIngestor $csv,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code')] string $pixieCode,
        #[Option('Sub code')] ?string $subCode = null,
        #[Option('Max records per file')] ?int $limit = null,
        #[Option('Batch size for progress')] int $batch = 500,
    ): int {
        $isDev = $this->env === 'dev';
        $limit ??= $isDev ? 50 : 0;
        $ctx = $this->pixie->getReference($pixieCode);
        $config = $ctx->config;

        $rawDir = $this->pixie->getSourceFilesDir($pixieCode, config: $config, subCode: 'raw');
        $jsonDir = $this->pixie->getSourceFilesDir($pixieCode, config: $config, subCode: 'json', autoCreate: true);

        if (!is_dir($rawDir)) {
            $io->error("Raw directory not found: $rawDir");
            return 1;
        }

        $io->title("Converting raw files to JSON: $rawDir -> $jsonDir");

        $finder = new Finder();
        $files = $finder->files()->in($rawDir);

        // Apply include/exclude filters from config
        if ($include = $config->getSource()->include) {
            $files->name($include);
        }

        if ($ignore = $config->getIgnored()) {
            $files->notName($ignore);
        }

        $processed = [];
        $fileToTableMap = $this->buildFileToTableMap($config);

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            $basename = $file->getFilenameWithoutExtension();
            $filename = $file->getFilename();

            // Skip unsupported formats
            if (!in_array($ext, ['csv', 'json', 'jsonl'])) {
                $io->note("Skipping unsupported file: $filename");
                continue;
            }

            // Determine which table/core this file maps to
            $tableName = $this->determineTableName($file, $fileToTableMap, $basename);
//            dd($tableName, $fileToTableMap, $basename, $file->getFilenameWithoutExtension(), $file->getExtension());

            if (!$tableName) {
                $io->warning("No table mapping found for file: $filename, using filename as table");
                $tableName = $basename;
            }

            $outputPath = $jsonDir . '/' . $tableName . '.json';
            $io->writeln("Converting: $filename -> " . basename($outputPath) . " (table: $tableName)");

            $count = $this->processFile($file->getRealPath(), $outputPath, $ext, $limit, $io);

            $processed[$tableName . '.json'] = $count;
            $io->writeln("  -> $count records");
        }

        // Write metadata file
        $metaData = [];
        foreach ($processed as $filename => $count) {
            $metaData[$filename] = $count;
        }

        file_put_contents($jsonDir . '/_files.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $io->success("Converted " . count($processed) . " files with " . array_sum($processed) . " total records");
        return 0;
    }

    private function processFile(string $inputPath, string $outputPath, string $ext, int $limit, SymfonyStyle $io): int
    {
        $output = fopen($outputPath, 'w');
        fwrite($output, "[\n");

        // Get iterator based on file type
        $iterator = match ($ext) {
            'json', 'jsonl' => $this->json->iterate($inputPath),
            'csv' => $this->requireCsv()->iterate($inputPath, null),
            default => throw new \RuntimeException("Unsupported extension: $ext")
        };

        $count = 0;
        $first = true;

        foreach ($iterator as $record) {
            if (!is_array($record)) continue;

            // Process the record with minimal cleanup
            $clean = $this->processRecord($record);

            if (empty($clean)) continue;

            // Write to JSON
            if (!$first) {
                fwrite($output, ',');
            }
            fwrite($output, "\n" . json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $first = false;
            $count++;

            if ($limit && $count >= $limit) break;
        }

        fwrite($output, "\n]\n");
        fclose($output);

        return $count;
    }

    private function processRecord(array $record): array
    {
        $clean = [];

        foreach ($record as $key => $value) {
            // Clean up field names
            $cleanKey = $this->cleanFieldName($key);

            // Clean up values
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '' || $value === 'NULL' || $value === '\\N') {
                    continue; // Skip empty/null values
                }

                // Basic type conversion
                if (ctype_digit($value)) {
                    $value = (int)$value;
                } elseif (is_numeric($value)) {
                    $value = (float)$value;
                } elseif (in_array(strtolower($value), ['true', 'false'])) {
                    $value = strtolower($value) === 'true';
                }
            }

            $clean[$cleanKey] = $value;
        }

        return $clean;
    }

    private function buildFileToTableMap($config): array
    {
        $map = [];

        // Get explicit file mappings from config
        if (method_exists($config, 'getFileToTableMap')) {
            $map = $config->getFileToTableMap();
        } elseif (method_exists($config, 'getFiles')) {
            $files = $config->getFiles();
            foreach ($files as $pattern => $tableName) {
                $map[$pattern] = $tableName;
            }
        }

        return $map;
    }

    private function determineTableName($file, array $fileToTableMap, string $basename): ?string
    {
        $filename = $file->getFilename();

        // First, check explicit file mapping rules from config
        foreach ($fileToTableMap as $pattern => $tableName) {
            if (preg_match($pattern, $filename)) {
                return $tableName;
            }
        }

        // Fallback: use filename as table name
        return $basename;
    }

    private function cleanFieldName(string $field): string
    {
        // Basic field name cleanup - more conservative than before
        $clean = trim($field);

        // Handle common patterns from museum data
        $clean = str_replace([
            'código', 'codigo',
            'descripción', 'descripcion',
            'número', 'numero'
        ], [
            'code', 'code',
            'description', 'description',
            'number', 'number'
        ], $clean);

        // Convert to snake_case
        $clean = strtolower($clean);
        $clean = preg_replace('/[^\w]/', '_', $clean);
        $clean = preg_replace('/_+/', '_', $clean);
        $clean = trim($clean, '_');

        // Handle some common museum field patterns
        if (str_starts_with($clean, 'url_id')) return 'url_id';
        if (str_contains($clean, 'catalogacion')) return 'code';
        if (str_contains($clean, 'ubicacion')) return 'loc';
        if (str_contains($clean, 'escena_principal')) return 'label';

        return $clean;
    }

    private function requireCsv(): CsvIngestor
    {
        if (!$this->csv) {
            throw new \RuntimeException('CsvIngestor not available. Install league/csv and wire CsvIngestor.');
        }
        return $this->csv;
    }
}