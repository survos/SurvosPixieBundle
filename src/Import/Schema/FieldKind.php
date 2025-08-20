<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Schema;

enum FieldKind: string
{
    case JsonScalar   = 'json_scalar';
    case JsonArray    = 'json_array';
    case RelationOne  = 'relation_one';
    case RelationMany = 'relation_many';
    case Label        = 'label';
    case Ignored      = 'ignored';
}
