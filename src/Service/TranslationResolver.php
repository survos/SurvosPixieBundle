<?php
// src/Service/TranslationResolver.php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Repository\StrRepository;

/**
 * Resolves Str codes -> localized text using Str::t with fallback to original.
 * Keeps a simple in-memory cache for the current request/CLI run.
 */
final class TranslationResolver
{
    /** @var array<string, array<string,string>> code => [locale => text] */
    private array $cache = [];

    public function __construct(
        private PixieService $pixieService,
//        private StrRepository $strRepo
    ) {}

    /**
     * @param string[] $codes
     * @return array<string,string> code => text
     */
    public function textsFor(array $codes, string $locale): array
    {
        $codes = array_values(array_unique(array_filter($codes, 'strlen')));
        if (!$codes) return [];

        // fetch missing (code, locale) pairs
        $missing = [];
        foreach ($codes as $c) {
            if (!isset($this->cache[$c][$locale])) {
                $missing[] = $c;
            }
        }

        if ($missing) {
            $ctx = $this->pixieService->getReference();
            $strRepo = $ctx->repo(Str::class);
            /** @var Str[] $strs */
            $strs = $strRepo->findBy(['code' => $missing]);
            foreach ($strs as $s) {
                $t = $s->t; // denormalized cache: [locale => text]
                $this->cache[$s->code][$locale] = $t[$locale] ?? $s->original;
            }
            // ensure unknown codes yield empty string rather than notice
            foreach ($missing as $c) {
                $this->cache[$c][$locale] ??= '';
            }
        }

        $out = [];
        foreach ($codes as $c) {
            $out[$c] = $this->cache[$c][$locale] ?? '';
        }
        return $out;
    }
}
