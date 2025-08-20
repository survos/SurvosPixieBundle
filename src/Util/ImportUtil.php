<?php

namespace Survos\PixieBundle\Util;

use Doctrine\Common\Collections\Collection;

final class ImportUtil
{
    /**
     * Produce a stable hash for mixed data by canonicalizing:
     *  - Assoc arrays: keys sorted lexicographically (with key-level ignore)
     *  - Lists: preserve order, canonicalize elements
     *  - Objects: DO NOT traverse entities; reduce to {__entity, id}
     */
    public static function contentHash(
        mixed $data,
        array $ignoreKeys = [],
        bool $unicodeNormalize = false
    ): string {
        $normalized = self::canonicalize($data, $ignoreKeys, $unicodeNormalize);
        $json = is_string($normalized)
            ? $normalized
            : json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    private static function canonicalize(mixed $v, array $ignoreKeys, bool $unicodeNormalize): mixed
    {
        // Strings (optional NFC)
        if (is_string($v)) {
            if ($unicodeNormalize && class_exists(\Normalizer::class)) {
                $v = \Normalizer::normalize($v, \Normalizer::FORM_C);
            }
            return $v;
        }

        // Scalars (ints, floats, bools, null)
        if (!is_array($v) && !is_object($v)) {
            return $v;
        }

        // Doctrine Collections → list of refs
        if ($v instanceof Collection) {
            $out = [];
            foreach ($v as $item) {
                $out[] = self::objectRef($item, $unicodeNormalize);
            }
            return $out;
        }

        // Objects → reduce to opaque reference; never walk properties
        if (is_object($v)) {
            return self::objectRef($v, $unicodeNormalize);
        }

        // Arrays
        if (\array_is_list($v)) {
            // list: preserve order
            foreach ($v as $i => $item) {
                $v[$i] = self::canonicalize($item, $ignoreKeys, $unicodeNormalize);
            }
            return $v;
        }

        // assoc: drop ignored keys (by name at this level), sort keys, recurse
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

    private static function objectRef(object $o, bool $unicodeNormalize): array|string
    {
        // Date/time → ISO 8601
        if ($o instanceof \DateTimeInterface) {
            return $o->format(\DateTimeInterface::ATOM);
        }

        // Enums
        if ($o instanceof \BackedEnum) {
            return (string)$o->value;
        }
        if ($o instanceof \UnitEnum) {
            return $o->name;
        }

        // Stringable (safe), e.g. Uid, Uuid
        if ($o instanceof \Stringable) {
            $s = (string)$o;
            if ($unicodeNormalize && class_exists(\Normalizer::class)) {
                $s = \Normalizer::normalize($s, \Normalizer::FORM_C);
            }
            return $s;
        }

        // Heuristic: treat anything with id / getId() as an entity; DO NOT read other props
        $id = null;
        if (method_exists($o, 'getId')) {
            // getId() should be scalar and side-effect free
            $id = $o->getId();
        } elseif (property_exists($o, 'id')) {
            // public id (no hook assumed)
            /** @psalm-suppress MixedPropertyFetch */
            $id = $o->id;
        }

        $class = \get_debug_type($o);
        if (is_scalar($id) || $id === null) {
            return ['__entity' => $class, 'id' => $id];
        }

        // Last resort: avoid deep traversal; use spl_object_id if id is weird
        return ['__entity' => $class, 'id' => \spl_object_id($o)];
    }
}
