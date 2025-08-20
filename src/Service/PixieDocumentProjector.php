<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\PixieContext;

final class PixieDocumentProjector
{
    public function __construct(private readonly EventQueryService $events) {}

    /** @return array<string,mixed> */
    public function project(PixieContext $ctx, Row $row, ?string $locale = null): array
    {
        $within = property_exists($row, 'idWithinCore') ? $row->idWithinCore
                 : (property_exists($row, 'isWithinCore') ? $row->isWithinCore : null);
        $label  = property_exists($row, 'label') ? $row->label : null;

        $doc = [
            'id'          => $within,
            'label'       => $label,
            'description' => property_exists($row, 'data') ? ($row->data['description'] ?? null) : null,
        ];

        if (property_exists($row, 'data')) {
            foreach (['materials','location','artist','date_created','insurance_value'] as $k) {
                if (array_key_exists($k, $row->data ?? [])) {
                    $doc[$k] = $row->data[$k];
                }
            }
        }

        $creators = $this->events->creatorsOf($ctx, $row);
        $doc['created_by'] = array_values(array_filter(array_map(
            fn(Row $r) => property_exists($r, 'label') ? $r->label : null,
            $creators
        )));

        $years = $this->events->createdYears($ctx, $row);
        $doc['created_at']      = $years ? min($years) : null;
        $doc['created_on_date'] = $this->events->firstCreatedDate($ctx, $row);

        return $doc;
    }
}
