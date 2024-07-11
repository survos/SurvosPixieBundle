<?php

namespace Survos\PixieBundle\Controller;

use League\Csv\Reader;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\WorkflowBundle\Controller\HandleTransitionsInterface;
use Survos\WorkflowBundle\Service\WorkflowHelperService;
use Survos\WorkflowBundle\Traits\HandleTransitionsTrait;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

//#[Route('/pixie')]
class PixieTransitionController extends AbstractController // implements HandleTransitionsInterface
{
//    use HandleTransitionsTrait;

    public function __construct(
        private PixieService $pixieService,
        private ?WorkflowHelperService $workflowHelperService=null,
//        #[AutowireLocator('workflow.state_machine')] private ?ServiceLocator $workflows=null,

    ) {

    }

    #[Route('/transition/{pixieCode}/{tableName}/{key}', name: 'pixie_transitionxx')]
    public function transition(Request $request,
                               string $pixieCode,
                               string $tableName,
                               string|int $key,
    #[MapQueryParameter] string $flowName,
    #[MapQueryParameter] string $transition,
    )
    {
        // get the item.  so maybe this belongs in show...
//        $workflow = $this->workflowHelperService->getWorkflow()
        dd(get_defined_vars(), $transition, $flowName);

    }
}
