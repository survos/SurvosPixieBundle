<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Schema;

final class CoreSchema
{
    /** @param array<string,FieldSpec> $fields */
    public function __construct(
        public readonly string $core,
        public readonly string $pk,
        public readonly array $fields,
        public readonly string $schemaVersion = 'v1',
    ) {}

    public function specFor(string $incomingHeader): ?FieldSpec
    {
        return $this->fields[$incomingHeader] ?? null;
    }
}
