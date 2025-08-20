<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;

/**
 * Builds SQLite views per core so you can query:
 *   SELECT * FROM <core>
 * (and also v_<core>)
 *
 * Columns:
 *   - id, label projected from row.id/row.label
 *   - others from json_extract(row.data, '$.<code>') AS "<code>"
 *
 * WHERE clause is baked with the literal core code (quoted),
 * because SQLite doesn't support bound parameters in CREATE VIEW.
 */
final class SqlViewService
{
    public function __construct(private readonly PixieService $pixie) {}

    /**
     * @return int number of cores processed
     */
    public function createCoreViews(string $pixieCode, bool $alsoPrefixed = true): int
    {
        $ctx  = $this->pixie->getReference($pixieCode);    // correct EM + schema
        $em   = $ctx->em;
        $conn = $em->getConnection();

        /** @var list<CoreDefinition> $coreDefs */
        $coreDefs = $ctx->repo(CoreDefinition::class)
            ->findBy(['ownerCode' => $pixieCode], ['core' => 'ASC']);

        if (!$coreDefs) {
            return 0; // no compiled schema; safe no-op
        }

        $actual = ['id', 'label', 'code'];

        foreach ($coreDefs as $cd) {
            $core = $cd->core ?? $cd->getCore();
            if (!$core) { continue; }

            /** @var list<FieldDefinition> $fields */
            $fields = $ctx->repo(FieldDefinition::class)
                ->findBy(['ownerCode' => $pixieCode, 'core' => $core], ['position' => 'ASC', 'id' => 'ASC']);

            $fieldNames = [];
            $selectExpr = [];
            $hasId = false; $hasLabel = false;

            foreach ($fields as $fd) {
                $code = $fd->code ?? $fd->getCode();
                if (!$code) { continue; }

                $fieldNames[] = $code;
                if ($code === 'id')    $hasId = true;
                if ($code === 'label') $hasLabel = true;

                if (\in_array($code, $actual, true)) {
                    $selectExpr[] = "row.$code";
                } else {
                    // Quote alias to support any property code safely
                    $selectExpr[] = "json_extract(data, '$.$code') AS \"$code\"";
                }
            }

            if (!$hasId)    { array_unshift($fieldNames, 'id');    array_unshift($selectExpr, 'row.id'); }
            if (!$hasLabel) { array_unshift($fieldNames, 'label'); array_unshift($selectExpr, 'row.label'); }

            // Build WHERE using a robust condition:
            // Prefer row.core_code if the column exists; otherwise map via subselect to core.id
            $quotedCore = $conn->quote($core);
            $where = $this->hasColumn($conn, 'row', 'core_code')
                ? "row.core_code = $quotedCore"
                : "row.core_id = (SELECT id FROM core WHERE code = $quotedCore LIMIT 1)";

            // Create two view names: <core> and v_<core>
            $viewNames = [$core];
            if ($alsoPrefixed) { $viewNames[] = 'v_' . $core; }

            $cols   = implode(', ', array_map(fn($c) => '"'.$c.'"', $fieldNames));
            $select = implode(', ', $selectExpr);

            foreach ($viewNames as $view) {
                try { $conn->executeStatement('DROP VIEW IF EXISTS "'.$view.'"'); } catch (\Throwable) {}

                $sql = sprintf(
                    'CREATE VIEW "%s" (%s) AS SELECT %s FROM row WHERE %s',
                    $view, $cols, $select, $where
                );

                try { $conn->executeStatement($sql); } catch (\Throwable $e) {
                    // You might log $e->getMessage() via a logger if desired
                }
            }
        }

        return \count($coreDefs);
    }

    private function hasColumn(\Doctrine\DBAL\Connection $conn, string $table, string $column): bool
    {
        try {
            $sm = $conn->createSchemaManager();
            $cols = $sm->listTableColumns($table);
            return isset($cols[$column]) || isset($cols[\strtolower($column)]);
        } catch (\Throwable) {
            return false;
        }
    }
}
