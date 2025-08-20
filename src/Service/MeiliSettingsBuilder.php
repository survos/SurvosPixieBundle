<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use ReflectionClass;
use ReflectionProperty;
use Survos\PixieBundle\Dto\Attributes\Mapper as MapperAttr;
use Survos\PixieBundle\Dto\Attributes\Map as MapAttr;

/**
 * Builds Meili index settings by scanning DTO mappers (#[Mapper]) and their #[Map] fields.
 *
 * Strategy:
 *  - Consider all DTO mappers whose class-level #[Mapper] matches pixie/core (when/except/cores).
 *  - Union field-level flags across those mappers to produce:
 *       filterableAttributes (facets), sortableAttributes, searchableAttributes.
 *  - displayedAttributes: default sensible set (id, label, description + facets).
 */
final class MeiliSettingsBuilder
{
    public function __construct(
        private readonly DtoRegistry $registry
    ) {}

    /**
     * @return array{filterableAttributes: string[], sortableAttributes: string[], searchableAttributes: string[], displayedAttributes: string[]}
     */
    public function build(string $pixieCode, string $core): array
    {
        $filterable = [];
        $sortable   = [];
        $searchable = [];

        // Iterate DTO entries in registry (already ordered by priority)
        foreach ($this->registry->entries as $e) {
            if ($e['when']   && !in_array($pixieCode, $e['when'], true))   continue;
            if ($e['except'] &&  in_array($pixieCode, $e['except'], true)) continue;
            if ($e['cores']  && !in_array($core,      $e['cores'], true))  continue;

            $rc = new ReflectionClass($e['class']);
            foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $attrs = $prop->getAttributes(MapAttr::class);
                if (!$attrs) continue;
                /** @var MapAttr $map */
                $map = $attrs[0]->newInstance();

                // field name will be the property name (normalized key)
                $field = $prop->getName();

                if ($map->facet && !in_array($field, $filterable, true)) {
                    $filterable[] = $field;
                }
                if ($map->sortable && !in_array($field, $sortable, true)) {
                    $sortable[] = $field;
                }
                if ($map->searchable || $map->translatable) {
                    if (!in_array($field, $searchable, true)) {
                        $searchable[] = $field;
                    }
                }
            }
        }

        // displayed: always show id/label/description if present, then facets
        $displayed = array_values(array_unique(array_merge(
            ['id','label','description'],
            $filterable
        )));

        return [
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'searchableAttributes' => $searchable,
            'displayedAttributes'  => $displayed,
        ];
    }
}
