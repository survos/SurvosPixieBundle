<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Ingest;

use JsonMachine\Items;

/**
 * Streams items from:
 *  - NDJSON (.jsonl): one object per line
 *  - JSON array (.json): array of objects (via halaxa/json-machine)
 */
final class JsonIngestor
{
    /**
     * Iterate a JSON **array** file as a stream of associative arrays.
     * Uses halaxa/json-machine to avoid loading the whole file in memory.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterateJsonArray(string $filename): \Generator
    {
        foreach (Items::fromFile($filename) as $row) {
            if (is_array($row)) {
                yield $row;
            } elseif (is_object($row)) {
                yield (array) $row;
            }
        }
    }

    /**
     * Iterate any supported file type:
     *  - .jsonl (or NDJSON): line-by-line JSON objects
     *  - .json (array): delegates to iterateJsonArray()
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterate(string $filename): \Generator
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        // NDJSON / JSONL
        if ($ext === 'jsonl' || $this->isNdjson($filename)) {
            $fh = fopen($filename, 'r');
            if (!$fh) {
                throw new \RuntimeException("Cannot open $filename");
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        yield $row;
                    }
                }
            } finally {
                fclose($fh);
            }
            return;
        }

        // JSON array
        if ($ext === 'json') {
            yield from $this->iterateJsonArray($filename);
            return;
        }

        // Fallback: assume JSON array
        yield from $this->iterateJsonArray($filename);
    }

    private function isNdjson(string $path): bool
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            return false;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // If the first non-empty line is not a '[' it's likely NDJSON
                return !str_starts_with($line, '[');
            }
        } finally {
            fclose($fh);
        }
        return false;
    }
}
