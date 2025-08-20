<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionProperty;
use Survos\PixieBundle\Dto\Attributes\Map as MapAttr;

final class StatsCollector
{
    /** @var array<string,array<string,array<string,int>>> owner->core->prop->nonempty */
    private array $nonEmpty = [];
    /** @var array<string,array<string,array<string,int>>> owner->core->prop->total */
    private array $totals   = [];
    /** @var array<string,array<string,array<string,array<string,int>>>> owner->core->prop->value->count */
    private array $facets   = [];

    /** cache of facet props per DTO class */
    private array $facetPropsByDto = [];

    public function __construct() {}

    /**
     * @param array<string,mixed> $normalized  // DTO output
     */
    public function accumulate(string $owner, string $core, array $normalized, ?string $dtoClass = null): void
    {
        // find which properties are facets from the DTO class
        $facetProps = $dtoClass ? $this->facetProps($dtoClass) : [];

        // increment totals/nonempty; for facets, also value distribution
        foreach ($normalized as $prop => $value) {
            // total
            $this->totals[$owner][$core][$prop] = ($this->totals[$owner][$core][$prop] ?? 0) + 1;

            // non-empty?
            $isNonEmpty = $this->isNonEmpty($value);
            if ($isNonEmpty) {
                $this->nonEmpty[$owner][$core][$prop] = ($this->nonEmpty[$owner][$core][$prop] ?? 0) + 1;
            }

            // facet distribution
            if (isset($facetProps[$prop]) && $isNonEmpty) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $label = $this->canon((string)$v);
                        $this->facets[$owner][$core][$prop][$label] = ($this->facets[$owner][$core][$prop][$label] ?? 0) + 1;
                    }
                } else {
                    $label = $this->canon((string)$value);
                    $this->facets[$owner][$core][$prop][$label] = ($this->facets[$owner][$core][$prop][$label] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * Flush in-memory counters to sqlite via UPSERT.
     * Keeps it fast and idempotent.
     */
    public function flush(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();

        // stat_property
        foreach ($this->nonEmpty as $owner => $byCore) {
            foreach ($byCore as $core => $props) {
                foreach ($props as $prop => $cnt) {
                    $total = $this->totals[$owner][$core][$prop] ?? $cnt;
                    $this->upsertProperty($conn, $owner, $core, $prop, $cnt, $total);
                }
            }
        }

        // stat_facet
        foreach ($this->facets as $owner => $byCore) {
            foreach ($byCore as $core => $props) {
                foreach ($props as $prop => $values) {
                    foreach ($values as $val => $cnt) {
                        $this->upsertFacet($conn, $owner, $core, $prop, $val, $cnt);
                    }
                }
            }
        }

        // reset memory
        $this->nonEmpty = [];
        $this->totals   = [];
        $this->facets   = [];
    }

    private function upsertProperty(Connection $conn, string $owner, string $core, string $prop, int $nonEmpty, int $total): void
    {
        // SQLite UPSERT
        $conn->executeStatement(
            'INSERT INTO stat_property (owner_code,core,property,non_empty,total)
             VALUES (:o,:c,:p,:ne,:t)
             ON CONFLICT(owner_code,core,property)
             DO UPDATE SET non_empty = stat_property.non_empty + excluded.non_empty,
                           total     = stat_property.total     + excluded.total',
            ['o'=>$owner,'c'=>$core,'p'=>$prop,'ne'=>$nonEmpty,'t'=>$total],
        );
    }

    private function upsertFacet(Connection $conn, string $owner, string $core, string $prop, string $value, int $count): void
    {
        $conn->executeStatement(
            'INSERT INTO stat_facet (owner_code,core,property,value,count)
             VALUES (:o,:c,:p,:v,:n)
             ON CONFLICT(owner_code,core,property,value)
             DO UPDATE SET count = stat_facet.count + excluded.count',
            ['o'=>$owner,'c'=>$core,'p'=>$prop,'v'=>$value,'n'=>$count],
        );
    }

    /** return canonical non-empty test */
    private function isNonEmpty(mixed $v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) {
            $vv = trim($v);
            if ($vv === '' || strcasecmp($vv,'N/D')===0 || strcasecmp($vv,'ND')===0) return false;
            return true;
        }
        if (is_array($v)) return count($v) > 0;
        if (is_object($v)) return (array)$v !== [];
        return true;
    }

    private function canon(string $v): string
    {
        return trim($v);
    }

    /** @return array<string,true> facet properties for a DTO class */
    private function facetProps(string $dtoClass): array
    {
        if (isset($this->facetPropsByDto[$dtoClass])) return $this->facetPropsByDto[$dtoClass];

        $rc = new ReflectionClass($dtoClass);
        $set = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(MapAttr::class);
            if (!$attrs) continue;
            /** @var MapAttr $map */
            $map = $attrs[0]->newInstance();
            if ($map->facet) $set[$prop->getName()] = true;
        }
        return $this->facetPropsByDto[$dtoClass] = $set;
    }
}
