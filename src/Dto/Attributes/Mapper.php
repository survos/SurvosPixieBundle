<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Attributes;

use Attribute;

/**
 * Marks a DTO class as a Pixie mapper.
 * - priority: higher runs earlier (first match wins)
 * - when:     restrict to these pixie codes (empty = all)
 * - except:   exclude these pixie codes
 * - cores:    restrict to these core names (empty = all)
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Mapper
{
    /**
     * @param array<string> $when
     * @param array<string> $except
     * @param array<string> $cores
     */
    public function __construct(
        public int $priority = 10,
        public array $when = [],
        public array $except = [],
        public array $cores = [],
    ) {}
}
