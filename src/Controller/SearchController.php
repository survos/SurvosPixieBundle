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
use Symfony\Bundle\SecurityBundle\Security;

class SearchController extends AbstractController
{
    public function __construct(
        private readonly PixieService $pixieService,
//        private ?AuthorizationCheckerInterface $authorizationChecker=null
    )
    {
    }

    #[Route('/meili/{pixieCode}/{tableName}', name: 'pixie_meili_browse', options: ['expose' => true])]
    public function meili(
        string $pixieCode,
        string $tableName,
                          IriConverterInterface $iriConverter,
                          InspectionService $inspectionService,
                          MeiliService $meiliService // @todo: move to constructor and make optional, throw error if missing and used
    ): Response
    {
        $indexName = $meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($pixieCode, $tableName));
        $operation = (new GetCollection())->withClass(MeiliItem::class);
        // pass in an object if all the parameters are available in the object,
        //  or pass in the class and the uri_variables.
        $apiUrl = $iriConverter->getIriFromResource(MeiliItem::class, operation:$operation, context: [
            'uri_variables' => ['indexName' => $indexName, 'tableName' => $tableName, 'pixieCode' => $pixieCode],
        ]);
        $config = $this->pixieService->getConfig($pixieCode);
        $table = $config->getTable($tableName);
        $gridColumns = [
            new Column(
                name: 'chevron',
                title: '>',
//                sortable: true,
                browsable: false
            ),
            new Column(
                name: 'pixie_key',
                title: 'id',
//                sortable: true,
                browsable: false
            ),
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
            if ($property->getSetting('g') == 'ignored') {
                continue;
            }
            if ($property->getIndex() === 'PRIMARY') {
                continue;
            }

            $column = new Column(
                name: $property->getCode(),
                browsable: $property->getIndex()=='INDEX',
                sortable: $property->getIndex()=='INDEX',
            );
            if ($condition = $property->getSetting('security')) {
//                $column->condition = $this->security->isGranted($condition); // sprintf("isGranted('%s')", $condition);
                $column->condition = $this->isGranted($condition); // sprintf("isGranted('%s')", $condition);
            }
            if ($title = $property->getSetting('title')) {
                $column->title = $title;
            }

            if (in_array($property->getCode(), $table->getTranslatable())) {
                // hmm
            } else {
                if ($column->sortable) {
                    $gridColumns[] = $column;
                }
            }
        }
//        https://mus.wip/api/meili/belvedere/object/mus_pixie_belvedere
        return $this->render('@SurvosPixie/pixie/grid.html.twig', [
            'indexName' => $indexName,
            'apiUrl' => $apiUrl,
            'pixieCode' => $pixieCode,
            'columns' => $gridColumns,
            'class' => MeiliItem::class,
            'tableName' => $tableName,
            'filter' => ['table' => $tableName]
        ]);
    }

//    private function isGranted($attribute, $subject = null): bool
//    {
//        if (! $this->authorizationChecker) {
//            throw new \Exception("try composer require symfony/security-bundle to use this feature");
//        }
//        return $this->authorizationChecker->isGranted($attribute, $subject);
//    }

}
