<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use ReflectionClass;
use ReflectionProperty;
use Survos\PixieBundle\Dto\Attributes\Map as MapAttr;

/**
 * Scans all active DTO mappers (via DtoRegistry) and derives Meilisearch settings:
 * - filterableAttributes (facets)   from #[Map(facet: true)]
 * - sortableAttributes              from #[Map(sortable: true)]
 * - searchableAttributes            from #[Map(searchable: true)] and/or translatable:true
 *
 * Usage: $builder->build($pixie, $core)
 */
final class DtosToMeiliSettings
{
    public function __construct(private readonly DtoRegistry $registry) {}

    /**
     * @return array{filterableAttributes:string[],sortableAttributes:string[],searchableAttributes:string[],displayedAttributes:string[]}
     */
    public function build(string $pixieCode, string $core): array
    {
        $filterable = [];
        $sortable   = [];
        $searchable = [];

        foreach ($this->registry->entries() as $e) {
            if ($e['when']   && !\in_array($pixieCode, $e['when'], true)) continue;
            if ($e['except'] &&  \in_array($pixieCode, $e['except'], true)) continue;
            if ($e['cores']  && !\in_array($core, $e['cores'], true)) continue;

            $rc = new ReflectionClass($e['class']);
            foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $attrs = $prop->getAttributes(MapAttr::class);
                if (!$attrs) continue;
                /** @var MapAttr $map */
                $map = $attrs[0]->newInstance();
                $field = $prop->getName();

                if ($map->facet        && !\in_array($field, $filterable, true)) $filterable[] = $field;
                if ($map->sortable     && !\in_array($field, $sortable, true))   $sortable[]   = $field;
                if (($map->searchable || $map->translatable) && !\in_array($field, $searchable, true)) $searchable[] = $field;
            }
        }

        $displayed = array_values(array_unique(array_merge(['id','label','description'], $filterable)));

        return [
            'filterableAttributes' => array_values(array_unique($filterable)),
            'sortableAttributes'   => array_values(array_unique($sortable)),
            'searchableAttributes' => array_values(array_unique($searchable)),
            'displayedAttributes'  => $displayed,
        ];
    }
}
