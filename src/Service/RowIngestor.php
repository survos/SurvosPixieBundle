<?php
// packages/pixie-bundle/src/Service/RowIngestor.php
declare(strict_types=1);
// src/Service/RowIngestor.php
namespace Survos\PixieBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Service\PixieService;

final class RowIngestor
{
    public function __construct(
        private PixieService $pixie,
        private LoggerInterface $logger,
    ) {}

    /**
     * Ingest a batch of rows (arrays or pre-built Row entities).
     * EM is taken from the CURRENT PixieContext; do not cache it across runs.
     *
     * @param iterable<Row|array> $records
     * @return array{inserted:int, updated:int}
     */
    public function ingest(iterable $records, int $batchSize = 500): array
    {
        $inserted = $updated = 0;
        $batch = [];

        foreach ($records as $r) {
            $batch[] = $r;
            if (\count($batch) >= $batchSize) {
                [$i, $u] = $this->flushBatch($batch);
                $inserted += $i; $updated += $u;
                $batch = [];
            }
        }
        if ($batch) {
            [$i, $u] = $this->flushBatch($batch);
            $inserted += $i; $updated += $u;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Resolve EM from current context right before DB work.
     * Never cache the EM on the service; contexts can change between calls.
     *
     * @param list<Row|array> $batch
     * @return array{int,int}
     */
    private function flushBatch(array $batch): array
    {
        $ctx = $this->pixie->requireContext();   // <— current context
        $em  = $ctx->em;                         // EntityManagerInterface

        $em->beginTransaction();
        try {
            $inserted = $updated = 0;

            foreach ($batch as $raw) {
                $row = $raw instanceof Row ? $raw : $this->hydrateRow($raw, $em);
                // … your upsert/merge logic here …
                $em->persist($row);
                $inserted++; // or set $updated++ based on your merge logic
            }

            $em->flush();
            $em->commit();
            $em->clear();                 // keep memory bounded
            return [$inserted, $updated];
        } catch (\Throwable $e) {
            $em->rollback();
            throw $e;
        }
    }

    /** Example: turn an array into a Row and bind translatable codes uniformly */
    private function hydrateRow(array $data, EntityManagerInterface $em): Row
    {
        // Build or lookup the Row (pseudo-code)
        $row = new Row(/* …keys… */);

        // For each field marked translatable in the pixie config:
        $ctx = $this->pixie->requireContext();
        $table = $ctx->config->getTable($row->getCoreCode());
        foreach ((array) $table->getTranslatable() as $field) {
            $sourceText = $data[$field] ?? null;
            if (!$sourceText) continue;

            // ensure a Str exists; code comes from your hash policy
            $code = $this->calcCode($sourceText, $ctx->config->getSource()->locale);
            /** @var Str|null $str */
            $str = $em->getRepository(Str::class)->find($code)
                ?? new Str($code, $sourceText, $ctx->config->getSource()->locale);

            $row->bindTranslatable($field, $str);
            $em->persist($str); // new ones get saved
        }

        // Also bind label/description via the same path for uniformity
        return $row;
    }

    private function calcCode(string $text, string $srcLocale): string
    {
        // your stable key/hash (same as TranslationClientService::calcHash if you like)
        return hash('xxh3', $srcLocale.'|'.$text);
    }
}
