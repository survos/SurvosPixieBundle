<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Schema;

final class FieldSpec
{
    public function __construct(
        public readonly string $code,
        public readonly FieldKind $kind,
        public readonly ?string $targetCore = null,
        public readonly ?string $delim = null,
        public readonly ?\Closure $caster = null,
    ) {}

    public static function label(string $code='label'): self { return new self($code, FieldKind::Label); }
    public static function json(string $code, ?\Closure $cast=null): self { return new self($code, FieldKind::JsonScalar, caster:$cast); }
    public static function jsonArray(string $code, string $delim='|', ?\Closure $cast=null): self { return new self($code, FieldKind::JsonArray, delim:$delim, caster:$cast); }
    public static function relOne(string $code, string $targetCore): self { return new self($code, FieldKind::RelationOne, targetCore:$targetCore); }
    public static function relMany(string $code, string $targetCore, string $delim='|'): self { return new self($code, FieldKind::RelationMany, targetCore:$targetCore, delim:$delim); }
    public static function ignored(string $code): self { return new self($code, FieldKind::Ignored); }
}
