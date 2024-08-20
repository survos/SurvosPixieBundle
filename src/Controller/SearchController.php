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
            'uri_variables' => ['indexName' => $indexName, 'pixieCode' => $pixieCode],
        ]);
        $config = $this->pixieService->getConfig($pixieCode);
        $table = $config->getTable($tableName);
        $gridColumns = ['pixie_key', 'table','key'];
        foreach ($table->getProperties() as $property) {
            $gridColumns[] = new Column(
                name: $property->getCode()
            );
        }
//        https://mus.wip/api/meili/belvedere/object/mus_pixie_belvedere
        return $this->render('@SurvosPixie/search/index.html.twig', [
            'apiUrl' => $apiUrl,
            'gridColumns' => $gridColumns,
            'class' => MeiliItem::class,
            'filter' => [
'table' => $tableName
            ]
        ]);
    }
}
