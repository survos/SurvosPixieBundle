<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Map;

use Survos\PixieBundle\Import\Schema\CoreSchema;
use Survos\PixieBundle\Import\Schema\FieldKind;
use Survos\PixieBundle\Import\Row\RowPayload;

final class RowMapper
{
    public function map(array $record, CoreSchema $schema): RowPayload
    {
        $id = (string)($record[$schema->pk] ?? '');
        assert($id !== '', "Missing PK '{$schema->pk}'");
        $p = new RowPayload($id, $schema->core, $schema->schemaVersion);
        $p->raw = $record;

        foreach ($record as $h => $v) {
            $spec = $schema->specFor($h);
            if (!$spec) { continue; }
            $x = is_string($v) ? trim($v) : $v;
            if ($spec->caster) { $x = ($spec->caster)($x); }

            switch ($spec->kind) {
                case FieldKind::Ignored:    break;
                case FieldKind::Label:      $p->label = (string)$x; break;
                case FieldKind::JsonScalar: $p->data[$spec->code] = $x; break;
                case FieldKind::JsonArray:
                    $p->data[$spec->code] = is_array($x)
                        ? $x
                        : ((is_string($x) && $x !== '') ? array_values(array_filter(array_map('trim', explode($spec->delim ?? '|', $x)))) : []);
                    break;
                case FieldKind::RelationOne:  $p->relations[$spec->code] = $x; break;
                case FieldKind::RelationMany:
                    $p->relations[$spec->code] = (is_string($x) && $x !== '')
                        ? array_values(array_filter(array_map('trim', explode($spec->delim ?? '|', $x))))
                        : [];
                    break;
            }
        }

        $p->label ??= "row {$schema->core}:{$p->idWithinCore}";
        return $p;
    }
}
