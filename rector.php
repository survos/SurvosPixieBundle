<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
//    ->withPaths([
//        __DIR__ . '/assets',
//        __DIR__ . '/config',
//        __DIR__ . '/demo',
//        __DIR__ . '/packages',
//        __DIR__ . '/public',
//        __DIR__ . '/src',
//    ])
    // uncomment to reach your current PHP version
     ->withPhpSets(php83: true)
    ->withPaths([__DIR__ . '/src/'])
    ->withSkipPath(__DIR__ . '/src/Debug')
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_84)
    ->withRules([
        Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
    ])
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
