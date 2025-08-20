<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Util;

final class IndexNameResolver
{
    public static function name(string $pixieCode, string $core, string $locale): string
    {
        return strtolower(sprintf('%s_%s_%s', $pixieCode, $core, $locale));
    }
}
