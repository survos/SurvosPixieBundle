<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use App\Entity\Core;
use App\Entity\Project;
use App\Event\FetchTranslationEvent;
use App\Message\TranslationMessage;
use App\Metadata\PixieInterface;
use App\Model\Translation;
use App\Service\PdoCacheService;
use Psr\Log\LoggerInterface;
use Survos\ApiGrid\Event\FacetEvent;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\GridGroupBundle\CsvSchema\Parser;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Survos\Scraper\Service\ScraperService;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use function Symfony\Component\String\u;

// see these for scraper bundle: https://foshttpcachebundle.readthedocs.io/en/latest/overview.html

class TranslationService
{
    const HASH_NAME = 'xxh3';
    const ENGINE='libre';
    const SOURCE='source';
    const NOT_TRANSLATED=Translation::PLACE_UNTRANSLATED;

    const TRANSLATION_KEY = '_translations'; // in the base pixie table, where the translations are stored by locale

    public function __construct(
        private readonly LibreTranslateService                         $libreTranslateService,
        protected ParameterBagInterface                       $bag,
        private readonly ScraperService                                $scraperService,
        private readonly CacheInterface                                $translationCache,
        private readonly LoggerInterface                               $logger,
//        #[AutowireMethodOf(service: PixieService::class)] \Closure $getFilename,
//        private PixieService $pixieService, // can't inject, mess circular dependency
        private readonly MessageBusInterface                           $bus,
        private readonly SurvosUtils $survosUtils,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $supportedLocales,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SluggerInterface $slugger,
        private readonly ?Stopwatch $stopwatch,
    )
    {
        $this->scraperService->setDir('../cache/')->setSqliteFilename('libre');

    }

    /**
     *
     *
     * @param \Generator $rows
     * @param array $translatableFields list of field codes that are translatable, e.g label, description
     * @param Core|null $core for debugging?
     * @return array
     */
    public function getTranslationKeys(\Generator $rows, array $translatableFields,
                                       string $sourceLocale,
                                       array $targetLocales
    ): array
    {
        $keys = [];
        if (!array_key_exists('label', $translatableFields)) {
            $translatableFields[] = 'label';
        }
        foreach ($rows as $row) {
//            AppService::assertKeyExists('id', $row);
//            $id = $row['id'];
            foreach ($translatableFields as $translatableField) {
                if (array_key_exists($translatableField, $row)) {
                    if (($value = $row[$translatableField]) && !is_numeric($value)) {
                        // if there's a label / code, this is probably a facet.
//                        $baseKey =
//                            (array_key_exists('id', $row) && $translatableField == 'label') ? $row['id'] : null;
                        $baseKey = $translatableField == 'label' ? $row['id']??$row['code'] : null;
//                        dd($baseKey, $value, $row, $translatableField);
//                        $baseKey = null; // for consistency?  Ugh, this is a problem with related tables

                        foreach ($targetLocales as $targetLocale) {
                            $key = $this->getKey($sourceLocale, $baseKey??$value, 'libre', $targetLocale);
                            dd($key);
//                            if ($baseKey) dd($key);
                            if (!in_array($key, $keys)) {
                                $keys[$key] = $value;
                                /* idea for debugging, but we need the value for translating
                                $keys[$key] = $sourceLocale == $targetLocale ? $value :
                                    sprintf("$targetLocale translation of [%s...] ", substr($value, 0, 36)); // for debugging, value not really needed.
                            */
                            }
                        }
                    }
                }
            }
        }
        return $keys;
    }

    static public function cacheKey(string $sourceKey, string $targetLocale): string
    {
        return sprintf("%s-%s", $sourceKey, $targetLocale); // the original
    }
    // works for both source language and translations
    public function getLocalizationData(
        string   $q,
                 $from = 'en',
                 $to = 'es',
        ?string $translation=null
    ): array
    {
        $sourceKey = self::calculateHash($q, $from);
        // unique to the translation

        // make a DTO?
        $data =[
            'key' => self::cacheKey($sourceKey, $to),
            'hash' => $sourceKey,
            'text' => ($from === $to) ? $q : $translation,
            'source' => $from,
            'target' => $to, // or null?
        ];
        return $data;

    }

    #[AsEventListener]
    public function onFacetEvent(FacetEvent $event): void
    {
        if (!$pixieCode = $event->context['uri_variables']['pixieCode']??null) {
            return;
        }
        $targetLocale = $event->getTargetLocale();
        $kv = $this->eventDispatcher->dispatch(new StorageBoxEvent($pixieCode))->getStorageBox();
        $tKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($pixieCode, isTranslation: true))->getStorageBox();
        $targetKeys = [];
        $isSource = false; // until we have the pixie source locale

        foreach ($event->getFacets() as $facetCode => $facet) {
            // if the facet matches a table, then translate it.
            foreach ($facet as $facetHash => $facetValue) {
                if ($kv->hasTable($facetCode)) {
                    if ($table = $kv->getTable($facetCode)) {
                        if (!str_starts_with($facetHash, $targetLocale)) {
                            $facetHash .=  "-{$targetLocale}";
                        } else {
                            $isSource = true;
                        }
                        if (!in_array($facetHash, $targetKeys)) {
                            $targetKeys[] = $facetHash;
                        }
                    }
                }
            }
        }
        if (count($targetKeys)) {
            $translatedStrings  =  $tKv->iterate(
                $isSource ? 'source' : TranslationService::ENGINE,
                pks: $targetKeys
            );

            $tStr = [];
            foreach ($translatedStrings as $tItem) {
                $tStr[$tItem->hash()] = $tItem->text();
            }
        }
        $x = [];
        $timer = $this->stopwatch->start('facet-translation', 'facets');
        foreach ($event->getFacets() as $facetCode => $facet) {
            foreach ($facet as $facetHash => $facetCount) {
//                dd($facetCode, $facetValue, $tStr, $facetValue, $facetHash);
                try {
                    $label = match ($facetCode) {
                        'countryCode' => Countries::getName(strtoupper($facetValue), $targetLocale),
                        'locale' => $facetValue, //  Languages::getName($facetValue, $targetLocale),
                        default => $tStr[$facetHash]??$facetHash
                    };
                    $x[$facetCode][$facetHash] = [
                        'label' => $label,
                        'count' => $facetCount
                    ];
                } catch (\Exception $exception) {
                    assert(false, "bad data! " . $exception->getMessage());
                    continue;
                    dd($key, $facetKey, $facetValue, $exception->getMessage());
                }
            }
        }
        $timer->stop();
        $event->setFacets($x);
    }

    public function getTranslationStorageBox(?string $pixieCode=null): ?StorageBox
    {
        if (!$pixieCode) {
            return null;
        }

        static $kv=[];
//        $pixieCode = PixieInterface::PIXIE_TRANSLATION; // could be tables inside, but need to manage config better
        // this could lead to too many files being open!


        assert($pixieCode);
        if (empty($kv[$pixieCode])) {
            $kv[$pixieCode] = $this->eventDispatcher->dispatch(new StorageBoxEvent(
                $pixieCode,
                isTranslation: true,
                tags: ['fetch']
            ))->getStorageBox();
        }
        $kv[$pixieCode]->select(self::ENGINE);
        return $kv[$pixieCode];
    }

    public function getTranslation($sourceHash, $target): ?string
    {
        $targetKey = self::cacheKey($sourceHash, $target);
        $kv = $this->getTranslationStorageBox();
        if ($kv->has($targetKey, 'libre')) {
            $targetItem = $kv->get($targetKey, 'libre');
            dd($targetItem);
        }
        return null;

    }
    public function translateLine(string   $q,
                                           $from = 'en',
                                           $to = 'es',
                                  string   $engine = 'libre',
                                  ?callable $callable = null
    ): ?string
    {
//        $trans = [];

//        $transCache = $this->getTranslationCache($from, $to);
//        dd($transCache, $this->translationCache);
        // redis, live if there's a connection
//        $transCache = $this->translationCache;

//        if (!$transCacheKey) {
//            $transCacheKey = $this->getKey($from, $q, $engine, $to); // do we need engine?
//        }
        // clean up q first?
        switch ($engine) {
            case 'deepl':
                $url = 'https://api-free.deepl.com/v2/translate';
                $sourceKeyName = 'text';
                $params = [
                    'text' => trim($q),
                    'source_lang' => strtoupper((string) $from),
                    'target_lang' => strtoupper((string) $to),
                ];
                $engineCallable = fn($data) => dd($data);
                break;
            case 'libre':
            case 'libre_live':
                $sourceKeyName = 'q';

                $base = $this->bag->get('libre_url');
                $url = $base; // . urlencode($q);
                //            if ($q = strtoupper($q)) {
                //                $q = u($q)->lower();
                //            }
                $params = [
                    'q' => $q,
                    'source' => $from,
                    'target' => $to
                ];
                $url .= sprintf('?source=%s&target=%s', $from, $to);
                $engineCallable = fn($result) => $result?->translatedText??null;
                break;
            default:
                assert(false, $engine . ' engine not handled.');

        }

        // skip using the translation pixie, the caller handles that.
//        $kv = $this->getTranslationStorageBox($pi);

        $value = $this->libreTranslateService->fetchTranslation($url, $params,
            $sourceKeyName, $q, $from, $to, $engineCallable, $callable);
        if (!$value) {
            $this->logger->error("Error translating $q from $from to $to");
//            dump($url, $params, $sourceKeyName, $q, $from, $to, $value);
//            assert($value, "no value");
            throw new \Exception("Is libretranslate running?");
        }
        return $value;
        if ($value) {
//                $item->expiresAt(null); // never, but seems to be 3 months in redis explorer
            $trans = $this->getLocalizationData($q, $from, $to, $value);
        }
        return $trans;

        /** @var CacheItem $item */
//        $item = $kv->get($transCacheKey, $engine, function ($key) {
//            dd($key, $from, $to, $q, );
//        })
        $kv->beginTransaction();
        // arguably this should happen during load, in a batch, since it's the source strings
        $orig = $this->getLocalizationData($q, $from, $from);
        $origKey = $orig['key'];
        if (!$kv->has($origKey)) {
            $kv->set($orig);
        }
        $transCacheKey = self::cacheKey($orig['hash'], $to);
//        $kv->get($orig['key'], callback: fn(string $key, string $tableName) =>  $kv->set($orig) );
        if (!$kv->has($transCacheKey)) {
            // don't cache invalid results
            if ($from == $to) {
                assert(false, "this should be for translation only");
                $value = $q;
            } else {
                $value = $this->libreTranslateService->fetchTranslation($url, $params, $sourceKeyName, $q, $from, $to, $engineCallable, $callable);
            }
            if ($value) {
//                $item->expiresAt(null); // never, but seems to be 3 months in redis explorer
                $trans  = $this->getLocalizationData($q, $from, $to, $value);
                // we could be smarter about this
                $kv->set($trans);

                // transCacheKey is unique to the translation
//                $kv->set($value, tableName: $engine, key: $transCacheKey);
//                $item->tag(['translation', $from]);
            } else {
                $this->logger->warning("No translation to $to of " . $q);
            }
//            $kv->select($engine);
//            $kv->beginTransaction();
//            $kv->set($trans);
//            $kv->commit();
        } else {

        }
            // do the translation
        $callable && $callable([
            'q' => $q,
            'value' => $value
        ]);
        if ($value) {
            $maxLen = 90;
            $this->logger->info(sprintf(__METHOD__ . " %s %s: %s", $engine, substr((string) $q, 0, $maxLen), substr((string) $value, 0, $maxLen)));
        }
        return $value;
    }

    /**
     * @param $translationCaches
     * @param mixed $targetLocale
     * @param Core|null $core
     * @param Parser $parser
     * @param string $sheetFilename
     * @return array
     */
    public function getExistingTranslations(string $sourceLocale, array $targetLocales, array $keys): array
    {
        // during debugging, we use the key values
        if (!array_is_list($keys)) {
            $keys = array_keys($keys);
        }
        $translations = [];
        $transCache = $this->translationCache;
//            if ($sourceLocale == $targetLocale) dd($keys);
            /** @var DoctrineDbalAdapter $transCache */
//            $transCache = $translationCaches[$targetLocale];
//            $filename = ($this->getTranslationCacheManager()->getFilename($transCache));
//            dd($filename);
////            if ($core->isObj()) dd($this->libreTranslateService->getTranslationCacheManager()->getFilename($)$translations, $keys);
//
//            if (!file_exists($filename)) {
//                return [];
//            }
            $items = $transCache->getItems($keys);
            try {
            } catch (\Exception $exception) {
                dd($exception, $this->libreTranslateService->getTranslationCacheManager()->getFilename($transCache));
            }
            foreach ($items as $key => $item) {
                if ($item->isHit()) {
//                    if ($existingTranslation = $tran[$targetLocale]->get($code)) {
//                        $row['_translations'][$targetLocale] = $existingTranslation;
                    $cleanedKey = $key; // $lookup[$key];
//                    dd($item->get(), $key, $item->getMetadata());
                    // hackish, the source and target are in the key.  Maybe use tags?
                    if (substr_count($key, '-') < 2) {
                        $translations[$sourceLocale][$cleanedKey] = $item->get();
//                    if (!preg_match('/-(.{2})$/', $key, $mm)) {
                    } else {
                        $targetLocale = u($key)->afterLast('-')->toString();
                        assert(Languages::exists($targetLocale), "invalid language ". $key);
                        $translations[$targetLocale][$cleanedKey] = $item->get();
                    }
//                    $translations[$targetLocale][$key] = $item->get();
//                                dd($existingTranslation);
//                                $translationCaches[$targetLocale]  = new CsvDatabase($ps->getSheetFilename($transSheet), 'id');
                } else {
//                                $io->warning("$code missing in $targetLocale " . $translationCaches[$targetLocale]->getFilename());
                }
            }
        return $translations;
    }

    /**
     * @param Project $project
     * @param SpreadsheetService $spreadsheetService
     * @param mixed $analysisSpreadsheet
     * @param ProjectService $ps
     * @return array<string, DoctrineDbalAdapter[]>
     */
    public function loadTranslationCaches(string $projectLocale, array $targets=[]): array
    {
        $transCache = [];
        foreach ($targets as $targetLocale) {
            $transCache[$projectLocale][$targetLocale] = $this->getTranslationCache($projectLocale, $targetLocale);
        }
        $transCache[$projectLocale][$projectLocale] = $this->getTranslationCache($projectLocale, $projectLocale);
        return $transCache;
    }

    public function getStringTranslationDir()
    {
        $translationDir = $this->bag->get('data_dir') . 'translations/';
        return SurvosUtils::createDir($translationDir);
    }

    public function getTranslationCacheManager(): PdoCacheService
    {
        static $cacheManager = null;
        if (!$cacheManager) {
            $cacheManager = PdoCacheService::create($this->getStringTranslationDir());
        }
        return $cacheManager;
    }


    public function getTargetLocales(Project $project, ?string $target = null): array
    {
        return $project->getTargetLocales();
    }

    public function getTranslationCache($from = 'en', $to = 'es', bool $reset = false): DoctrineDbalAdapter
    {
//        assert(false, "inject the translation cache instead"); // except during migration!
        static $transCache = [];
        $cacheManager = $this->getTranslationCacheManager();
        $prefix = sprintf("%s-%s", $from, $to);
        if (!$cache = $transCache[$prefix] ?? false) {
            if ($reset) {
                $this->logger->error("WARNING: RESETTING CACHE " . $cacheManager->getFilename($cache));
                assert(false);
            }
            $cache = $cacheManager->getOtherCache($prefix, $reset);
            $cache->setLogger($this->logger);
            $transCache[$prefix] = $cache;
        }
        return $cache;

//        $transCache = new CsvDatabase($sourceDir . $prefix . '.csv', 'key');
//        return $transCache;
    }

    public function getTranslationsByCodes(string $from, string $to, array $codes): array
    {
//        $transCache = $this->getTranslationCache($from, $to);
        $transCache = $this->translationCache;
        $translations = [];
        $keys = [];
        $reverse = [];
        foreach ($codes as $value) {
            foreach (['libre'] as $engine) {
                if ($value && !is_numeric($value)) {
                    $key = $this->getKey($from, $value, $engine, $to);
                    $reverse[$key] = $value;
                    $keys[] = $key;
                }
            }
        }
        if (count($keys)) {
            $items = $transCache->getItems($keys);
            foreach ($items as $key => $item) {
                $translations[$reverse[$key]] = $item->get();
            }
        }
        return $translations;


    }

    public function getKey( string $sourceLocale, ?string $source, string $engine='libre', ?string $target=null): ?string
    {
        if (empty($source)) {
            return null;
        }
        assert( Languages::exists($sourceLocale), $sourceLocale);
        if ($target) {
            assert( Languages::exists($target), $target);
        }
        assert(in_array($engine, ['libre','deep-l','google']), "Invalid engine: $engine");
        $source = SurvosUtils::slugify($source, maxLength: 1024);
        assert(in_array($engine, ['libre', 'deepl']), "bad engine: " . $engine);
        // if the base has an underscore, it means it's really a key, like for the facets.
        if ($source == SurvosUtils::slugify($source)) {
            $base = $source;
        } else {
            $base = strlen($source) < 24 ? AppService::slugify($source, 1024)
                : hash(self::HASH_NAME, $source);
            assert(SurvosUtils::slugify($base) == $base); // how did we get a ':' in the key?
        }
//        if (!$base) {
//        }
//        assert($base == strtolower($base), $source . ' ' . $base . ' should be lcase');
        if ($sourceLocale == $target) {
            return sprintf("%s-%s", $sourceLocale, $base);
        } else {
            return sprintf("%s-%s-%s-%s", $sourceLocale, $base, $engine, $target);

        }
    }

    public static function calculateHash(string $data, string $from): string
    {
        // we need "from" because of things like "nine=no,nueve"
        return $from . '-' . hash(algo: self::HASH_NAME, data: $data);
    }

}

