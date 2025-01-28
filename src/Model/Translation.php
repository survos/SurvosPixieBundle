<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Model;

use Survos\LibreTranslateBundle\Service\TranslationClientService;

class Translation
{
    const PLACE_UNTRANSLATED = 'u';
    const PLACE_TRANSLATED = 't';
//            'target' => $to, // or null?

    public function __construct(
        private ?string $source, // source language
        private ?string $text, // the source text, for calculating the key
        private ?string $target=null,
        private ?string $hash=null, // the hash of the text of the source
        private ?string $tableName=null,
        private ?string $field=null,
    )
    {
        // source hash is calculated, otherwise it's required to be passed in (or pass the source itself in?)
        if (!$this->target || ($this->source == $this->target)) {
            $this->hash  = self::calculateHash($source, $this->text);
        } else {
            assert($this->hash, "you must pass the source hash in if a translation");
        }
//        $this->key = self::cacheKey($sourceKey, $to),
    }

    public function toArray(): array
    {
        $base =  [
            'hash' => $this->hash,
            'locale' => $this->source,
            'text' => $this->text,
        ];
        if ($this->target <> $this->source) {
            $base = array_merge($base, [
                'key' => self::cacheKey($this->hash, $this->target),
                'target' => $this->target
            ]);
        } else {
            foreach (['tableName', 'field'] as $key) {
                if ($this->{$key}) {
                    $base[$key] = $this->{$key};
                }
            }
        }
        return $base;

    }

    public function isSource(): bool
    {
        return ($this->source === $this->target) || is_null($this->target);

    }

    static public function cacheKey(string $sourceKey, string $targetLocale): string
    {
        return sprintf("%s-%s", $sourceKey, $targetLocale); // the original
    }
    private static function calculateHash(string $from, string $data): string
    {
        // must be in sync. not sure if we absolutely need the prefix.
        return TranslationClientService::calcHash($data, $from);
//
//        // we need "from" because of things like "nine=no,nueve"
//        // needs a prefix to avoid being interpreted as a number in json
//        return $from . '-' .hash(algo: self::HASH_NAME, data:  $data);
    }


    public function getText(): ?string
    {
        return $this->text;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function getKey(): ?string
    {
        return self::cacheKey($this->hash, $this->target);
    }


}
