<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Ingest;

use League\Csv\Reader;

/**
 * Streams CSV rows as associative arrays using first row as header.
 */
final class CsvIngestor
{
    /**
     * @param string[]|null $expectedHeaders Optional: used to realign rows if header is mismatched
     * @return \Generator<int,array<string,mixed>>
     */
    public function iterate(string $filename, ?array $expectedHeaders = null): \Generator
    {
        $reader = Reader::createFromPath($filename, 'r');
        $reader->setHeaderOffset(0);
        $headers = $reader->getHeader() ?: [];

        foreach ($reader->getRecords() as $row) {
            if ($expectedHeaders && \count($row) !== \count($expectedHeaders)) {
                $row = \array_combine($expectedHeaders, \array_values($row));
            }
            if (\is_array($row)) {
                yield $row;
            }
        }
    }
}
