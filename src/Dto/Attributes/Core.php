<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Attributes;

use Attribute;

/**
 * Marks a property as a reference to another "core".
 * - target: target core code (e.g. 'cul', 'tec')
 * - multiple: true for many refs (arrays)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Core
{
    public function __construct(
        public ?string $target = null,
        public bool $multiple  = false,
    ) {}
}
