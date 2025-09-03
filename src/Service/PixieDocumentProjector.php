<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\PixieContext;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class PixieDocumentProjector
{
    public function __construct(private readonly EventQueryService $events,
                                private readonly NormalizerInterface $normalizer,
                                private readonly SerializerInterface $serializer) {}

    /** @return array<string,mixed> */
    public function project(PixieContext $ctx, Row $row, ?string $locale = null): array
    {

        $within = property_exists($row, 'idWithinCore') ? $row->idWithinCore
                 : (property_exists($row, 'isWithinCore') ? $row->isWithinCore : null);
        $label  = property_exists($row, 'label') ? $row->label : null;

        $doc = [
            'id'          => $within,
            'label'       => $label,
//            'description' => property_exists($row, 'data') ? ($row->data['description'] ?? null) : null,
        ];
        $normalized = $this->normalizer->normalize($row, 'array', ['groups' => ['row.read', 'row.images']]);
        $doc = array_merge($doc, $row->data, $normalized);
        $doc['data'] = []; // will be removed
        unset($doc['data']);
        // for debugging
//        $doc['raw'] = $row['raw'];


//        $creators = $this->events->creatorsOf($ctx, $row);
//        $doc['created_by'] = array_values(array_filter(array_map(
//            fn(Row $r) => property_exists($r, 'label') ? $r->label : null,
//            $creators
//        )));

//        $years = $this->events->createdYears($ctx, $row);
//        $doc['created_at']      = $years ? min($years) : null;
//        $doc['created_on_date'] = $this->events->firstCreatedDate($ctx, $row);
        $doc = SurvosUtils::removeNullsAndEmptyArrays($doc);

        return $doc;
    }
}
