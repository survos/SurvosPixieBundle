<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Attributes;

use Attribute;

/**
 * Map a DTO property from a source payload and annotate Meili metadata.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Map
{
    /**
     * @param array<string> $when
     * @param array<string> $except
     */
    public function __construct(
        public ?string $source = null,
        public ?string $regex  = null,
        public ?string $if     = null,
        public ?string $delim  = null,

        // Mapping control
        public int $priority   = 10,
        public array $when     = [],
        public array $except   = [],

        // Meili metadata
        public bool $facet        = false,   // filterableAttributes
        public bool $sortable     = false,   // sortableAttributes
        public bool $searchable   = false,   // searchableAttributes
        public bool $translatable = false,   // helpful for your translation pipeline
    ) {}
}
