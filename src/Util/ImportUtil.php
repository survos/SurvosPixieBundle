<?php

namespace Survos\PixieBundle\Util;

final class ImportUtil
{
    /**
     * Produce a stable hash for mixed data by canonicalizing:
     *  - Objects/assoc arrays: keys sorted lexicographically
     *  - Lists: preserve order, but canonicalize elements
     *  - Optionally ignore certain keys and NFC-normalize strings
     */
    public static function contentHash(
        mixed $data,
        array $ignoreKeys = [],
        bool $unicodeNormalize = false
    ): string {
        $normalized = self::canonicalize($data, $ignoreKeys, $unicodeNormalize);

        // Minimal, deterministic JSON (don’t preserve zero fraction)
        $json = is_string($normalized)
            ? $normalized
            : json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }

    private static function canonicalize(mixed $v, array $ignoreKeys, bool $unicodeNormalize): mixed
    {
        // Strings: optional NFC to avoid accent composition drift
        if (is_string($v)) {
            if ($unicodeNormalize && class_exists(\Normalizer::class)) {
                $v = \Normalizer::normalize($v, \Normalizer::FORM_C);
            }
            return $v;
        }

        // Objects → arrays, then canonicalize
        if (is_object($v)) {
            // Prefer a toArray() if the object provides one
            if (method_exists($v, 'toArray')) {
                $v = $v->toArray();
            } else {
                $v = get_object_vars($v);
            }
        }

        // Arrays
        if (is_array($v)) {
            if (\array_is_list($v)) {
                // list: preserve order, canonicalize elements
                foreach ($v as $i => $item) {
                    $v[$i] = self::canonicalize($item, $ignoreKeys, $unicodeNormalize);
                }
                return $v;
            }

            // assoc: remove ignored keys at this level, sort keys, recurse
            foreach ($ignoreKeys as $k) {
                if (array_key_exists($k, $v)) {
                    unset($v[$k]);
                }
            }
            ksort($v, SORT_STRING);
            foreach ($v as $k => $item) {
                $v[$k] = self::canonicalize($item, $ignoreKeys, $unicodeNormalize);
            }
            return $v;
        }

        // Numbers/bools/null: leave as-is (json_encode is deterministic here)
        return $v;
    }
}
