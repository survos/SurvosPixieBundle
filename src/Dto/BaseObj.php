<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto;

/**
 * Common base for museum DTOs.
 * Override afterMap() to derive fields from src/normalized data.
 */
abstract class BaseObj
{
    /**
     * Hook invoked after mapping. You can compute derived fields here.
     * @param array<string,mixed> $norm  (normalized fields so far)
     * @param array<string,mixed> $src   (original record)
     */
    public function afterMap(array &$norm, array $src): void
    {
        // no-op by default
    }
}
