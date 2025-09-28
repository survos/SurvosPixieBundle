<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

/**
 * Single source of truth for Meilisearch index naming conventions
 */
class IndexNamingService
{
    public static function createIndexName(string $pixieCode, string $locale): string
    {
        // Normalize locale to lowercase for consistency
        $normalizedLocale = strtolower($locale);
        
        // Ensure pixie code is safe for index names (alphanumeric + underscore)
        $safePixieCode = preg_replace('/[^a-zA-Z0-9_]/', '_', $pixieCode);
        
        return $safePixieCode . '_' . $normalizedLocale;
    }

    public static function parseIndexName(string $indexName): ?array
    {
        if (preg_match('/^(.+)_([a-z]{2,3})$/', $indexName, $matches)) {
            return [
                'pixieCode' => $matches[1],
                'locale' => $matches[2]
            ];
        }
        
        return null;
    }

    public static function getAvailableIndexNames(string $pixieCode, array $locales): array
    {
        $indexNames = [];
        foreach ($locales as $locale) {
            $indexNames[$locale] = self::createIndexName($pixieCode, $locale);
        }
        return $indexNames;
    }
}
