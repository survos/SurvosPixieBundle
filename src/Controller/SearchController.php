<?php

namespace Survos\PixieBundle\Controller;

use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Symfony\Routing\IriConverter;
use App\Entity\MeiliItem;
use Survos\ApiGrid\Model\Column;
use Survos\ApiGrid\Service\MeiliService;
use Survos\InspectionBundle\Services\InspectionService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(private readonly PixieService $pixieService)
    {
    }

    #[Route('/meili/{pixieCode}/{tableName}', name: 'pixie_meili_browse')]
    public function meili(
        string $pixieCode,
        string $tableName,
                          IriConverterInterface $iriConverter,
                          InspectionService $inspectionService,
                          MeiliService $meiliService): Response
    {
        $indexName = $meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($pixieCode));
        $operation = (new GetCollection())->withClass(MeiliItem::class);
        // pass in an object if all the parameters are available in the object,
        //  or pass in the class and the uri_variables.
        $apiUrl = $iriConverter->getIriFromResource(MeiliItem::class, operation:$operation, context: [
            'uri_variables' => ['indexName' => $indexName, 'tableName' => $tableName, 'pixieCode' => $pixieCode],
        ]);
        $config = $this->pixieService->getConfig($pixieCode);
        $table = $config->getTable($tableName);
        $gridColumns = [
            'pixie_key',
            new Column(
                name: 'thumbnail',
                browsable: false
            ),
            new Column(
                name: 'tombstone',
                className: 'tombstone-heading',
                browsable: false
            ),
        ];
//        foreach ($table->getTranslatable() as $field) {
//            $gridColumns[] = new Column(name: $field, browsable: false,
//                className: 'custom-heading',
//                searchable: true, order: 7); // searchable should highlight
//        }
        // could also go through indexes
        foreach ($table->getProperties() as $property) {

            $column = new Column(
                name: $property->getCode(),
                browsable: $property->getIndex()=='INDEX',
            );
            if (in_array($property->getCode(), $table->getTranslatable())) {
                // hmm
            } else {
                $gridColumns[] = $column;
            }
        }
//        https://mus.wip/api/meili/belvedere/object/mus_pixie_belvedere
        return $this->render('@SurvosPixie/pixie/grid.html.twig', [
            'indexName' => $indexName,
            'apiUrl' => $apiUrl,
            'pixieCode' => $pixieCode,
            'columns' => $gridColumns,
            'class' => MeiliItem::class,
            'filter' => ['table' => $tableName]
        ]);
    }
}
