<?php

namespace Survos\PixieBundle\Controller;

use League\Csv\Reader;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\StorageBox;
use Survos\WorkflowBundle\Service\WorkflowHelperService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

//#[Route('/pixie')]
class PixieController extends AbstractController
{
    const TRANSITION_RESET='_reset';

    public function __construct(
        private ParameterBagInterface  $bag,
        private PixieService           $pixieService,
        private MessageBusInterface $bus,
        private UrlGeneratorInterface  $urlGenerator,
        private ?WorkflowHelperService $workflowHelperService = null,
        private ?ChartBuilderInterface $chartBuilder = null,
    )
    {

    }

    private function getPixieConf(string $pixieCode, bool $throwIfMissing = true): ?string
    {

        dd($pixieCode, $this->pixieService->getConfigDir());
        $dirs = [
            $this->bag->get('config_dir'),
            $this->bag->get('kernel.project_dir') . "/config/packages/pixie/",
        ];
        $pixieCode = str_replace('.pixie', '', $pixieCode); // hack!
        foreach ($dirs as $dir) {
            $fn = $dir . "/$pixieCode.yaml";
            if (file_exists($fn)) {
                return $fn;
            }
        }
        assert(false, "$fn not found");
        return null;
    }


    #[Route('/show/{pixieCode}/{tableName}/{key}', name: 'pixie_show_record', requirements: ['key' => '.+'])]
    #[Route('/transition/{pixieCode}/{tableName}/{key}', name: 'pixie_transition', requirements: ['key' => '.+'])]
    public function show_record(
        Request                      $request,
        string                       $pixieCode,
        string                       $tableName,
        string                       $key,
        #[MapQueryParameter] ?string $transition = null,
        #[MapQueryParameter] ?string $flowName = null,
        #[MapQueryParameter] ?string $index = null,
        #[MapQueryParameter] ?string $value = null,
        #[MapQueryParameter] int     $limit = 5
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $kv->select($tableName);
        $pk = $kv->getPrimaryKey($tableName);
        $item = $kv->get($key, $tableName);
        $conf = $this->pixieService->getConfig($pixieCode);

        $this->pixieService->populateRecordWithRelations($item, $conf, $kv);
        // what a pain, we need to store this somewhere else!

        $table = $conf->getTables()[$tableName];
        $workflow = $table->getWorkflow();
        assert($item::class == Item::class);
        if (!$item) {
            throw new NotFoundHttpException("No item $key in $tableName / $pixieCode");
        }

        if ($request->get('_route') == 'pixie_transition') {
            if ($transition == self::TRANSITION_RESET) {
                $kv->beginTransaction();
                $kv->set(
                    $item->getRp([
                        'marking' => null
                    ]
                ), key: $key, tableName: $tableName, mode: $kv::MODE_PATCH);
                $kv->commit();
                return $this->redirectToRoute('pixie_show_record', $item->getRp());

                dd($item);

            }
            $message = new PixieTransitionMessage($pixieCode, $key, $tableName, $transition, $flowName);
            // call it, rather than dispatch, since this is interactive, unless we pass async.
            $this->pixieService->handleTransition($message);

            return $this->redirectToRoute('pixie_show_record', $item->getRp());
        }
        $this->pixieService->populateRecordWithRelations($item, $conf, $kv);


//        dd($workflow, $table);
//        $item = (array)$item;
        return $this->render('@SurvosPixie/pixie/show.html.twig', [
            'kv' => $kv,
            'workflowEnabled' => (bool)$workflow, // comes from config
            'workflow' => $workflow,
            'key' => $key,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'row' => $item,
            'columns' => array_keys((array)$item),
        ]);

    }

    private function flattenArray(array $array): array
    {
        foreach ($array as $idx => $row) {
            foreach ($row as $var => $value) {
                if (is_iterable($value)) {

//                    $row[$var] = $this->flattenArray($value);  json_encode($value, JSON_UNESCAPED_SLASHES);
                }
            }
        }
        return $row;
    }

    #[Route('/browse/{pixieCode}/{tableName}.{_format}', name: 'pixie_browse')]
    public function browse(
        Request                      $request,
        string                       $pixieCode,
        string                       $tableName,
        string                       $_format = 'html',
        #[MapQueryParameter] ?string $index = null,
        #[MapQueryParameter] ?string $value = null,
        #[MapQueryParameter] int     $limit = 50,
        #[MapQueryParameter] int     $offset = 0,
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $where = [];
        if ($index) {
            $where[$index] = $value ?: null;
        }
        $kv->select($tableName);
        $keyName = $kv->getPrimaryKey($tableName);

        $iterator = $kv->iterate($tableName, $where);
//        foreach ($iterator as $key => $item) {
//            dd($item);
//        }


        $table = $kv->getTable($tableName);
        // @todo: refactor to pass properties to datatables
        foreach ($table->getProperties() as $property) {
            $columns[] = $property->getCode();
        }
//        if ($firstItem = $iterator->current()) {
////            dd($firstRow, $firstItem);
//
//            $columns = array_keys((array)$firstItem->getData());
//            $iterator = $kv->iterate($tableName, $where);
////            $iterator->rewind();
//        } else {
//            $columns = ['value'];
//        }
//        dd($where, $firstRow, $columns);

        // @todo: flatten nested json, apply view, column rules, etc.
        if ($_format == 'json') {
            $flattenRows = [];
            $idx = 0;
            foreach ($iterator as $key => $item) {
                $row = (array)$item->getData();
//                assert($row, "Invalid data in $key " . $kv->getFilename());
                $idx++;
                foreach ($row as $var => $value) {
                    if ($var == $keyName) {
                        $row[$var] = sprintf(
                            "<a href='%s'>%s</a>",
                            $this->urlGenerator->generate('pixie_show_record', [
                                'pixieCode' => $pixieCode,
                                'tableName' => $tableName,
                                'key' => $value
                            ]), $value
                        );
                    } elseif (is_iterable($value)) {
                        $row[$var] = json_encode($value, JSON_UNESCAPED_SLASHES);
                    }
                }
                $flattenRows[] = $row;
                if ($limit && ($idx >= $limit)) {
                    break;
                }
            }
            return new JsonResponse($flattenRows);
        }
        if (in_array('marking', $columns)) {
            array_unshift($columns, $keyName, 'marking');
        }
        array_unshift($columns, $keyName);
        $columns = array_unique($columns);
        $iterator->rewind();

//        $rows = [];
//        foreach ($iterator as $key => $item) {
//            $rows[$key] =  $item->getData();
//        }
//        foreach ($kv->iterate($tableName, $where) as $row) {
//            dd($row);
//        }

        // there may be a better way.
        $remoteUrl = $this->urlGenerator->generate(
            $request->get('_route'),
            [...$request->get('_route_params'),
                ...$request->query->all(),
                '_format' => 'json']
        );
        $columns = array_values(array_unique($columns));
        // see kaggle for inspiration, https://www.kaggle.com/datasets/shivamb/real-or-fake-fake-jobposting-prediction/data
        return $this->render('@SurvosPixie/pixie/browse.html.twig', [
            'pixieCode' => $pixieCode,
            'tableName' => $tableName,
//            'kv' => $kv, // avoidable?/
            'remoteUrl' => $remoteUrl,
            'iterator' => [], // $firstRow ? $iterator : [],
            'keyName' => $keyName,
            'columns' => $columns,
            'filename' => $kv->getFilename(),
            'kv'=>$kv
        ]);

    }

    #[Route('/', name: 'pixie_browse_configs')]
//    #[Template()]
    public function browsePixies(): array|Response
    {

        $configs = $this->pixieService->getConfigFiles();
        // cache candidate!
        $tables = [];
        foreach ($configs as $pixieCode => $config) {
            $kv = $this->pixieService->getStorageBox($pixieCode);
            foreach ($kv->getTables() as $tableName => $table) {
                $tables[$pixieCode][$tableName]['count'] = $kv->count($tableName);
                foreach ($kv->getIndexes($tableName) as $indexName) {
                    $tables[$pixieCode][$tableName]['indexes'][$indexName] = $kv->getCounts($indexName, $tableName);
                }

            }
        }
        return $this->render('@SurvosPixie/pixie/index.html.twig', [
            'dir' => $this->pixieService->getConfigDir(),
            'configs' => $configs,
            'tables' => $tables,
        ]);
//        return $this->render(, );
    }

    #[Route('/{pixieCode}', name: 'pixie_homepage')]
    public function pixie_overview(
        string                   $pixieCode,
        #[MapQueryParameter] int $limit = 25
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $tables = [];

        foreach ($kv->getTableNames() as $tableName)
        {
            $counts = [];
            foreach ($kv->getIndexes($tableName) as $indexName) {
                $counts[$indexName] = $kv->getCounts($indexName, $tableName, $limit);
            }
                $tables[$tableName] = [
                    'count' => $kv->count($tableName),
                    'counts' => $counts
                ];
        }
        return $this->render('@SurvosPixie/pixie/homepage.html.twig', [
            'kv' => $kv,
            'tables' => $tables,
            'pixieCode' => $pixieCode,
            ]);
    }


    #[Route('/schema/{pixieCode}', name: 'pixie_schema')]
    public function schema(
        string                   $pixieCode,
    ): Response
    {
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        return $this->render('@SurvosPixie/pixie/schema.html.twig', [
            'kv' => $this->pixieService->getStorageBox($pixieCode),
            'config' => $this->pixieService->getConfig($pixieCode),
            'pixieCode' => $pixieCode,
        ]);
    }

        #[Route('/table/{pixieCode}/{tableName}', name: 'pixie_table')]
    public function home(
        string                   $pixieCode,
        string                   $tableName,
        #[MapQueryParameter] int $limit = 25
    ): Response
    {
        $chartBuilder = $this->chartBuilder;
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        assert(file_exists($pixieFilename));
        $kv = $this->pixieService->getStorageBox($pixieCode);
        {
            $count = $kv->count($tableName);
//            dd($tableName, $count);
            $tables[$tableName] = [
                'first' => $kv->iterate($tableName)->current(),
                'count' => $kv->count($tableName) // we could cache this someday, like ->closeWithCounts()
            ];

            $charts = [];
            $table = $kv->getTable($tableName);
            foreach ($table->getProperties() as $property) {
                if (!$indexName = $property->getIndex()) {
                    continue;
                }
                // primary is required and maybe min/max?
                if ($indexName === 'PRIMARY') {
                    continue;
                }
                $indexName = $property->getCode();
                $labels = [];
                $values = [];
                $counts = $kv->getCounts($indexName, $tableName, $limit);
                if (count($counts) === 0) {
                    continue;
                }
                foreach ($counts as $count) {
                    $labels[] = $count['value']; // the property name
                    $values[] = $count['count'];
                    // @todo: composer require phpcolor/bootstrap-colors
                    $colors[] = sprintf('rgb(%d, %d, %d)',
                        rand(0, 255),
                        rand(0, 255),
                        rand(0, 255)
                    );
                }
                $chart = $chartBuilder->createChart(
                    str_contains($indexName, 'year') ? Chart::TYPE_LINE :
                        Chart::TYPE_PIE
                );

                $chart->setData([
                    'labels' => $labels,
                    'datasets' => [
                        [
                            'label' => $indexName . "/$tableName",
                            'backgroundColor' => $colors,
                            'borderColor' => 'rgb(255, 99, 132)',
                            'data' => $values,
                        ],
                    ],
                ]);

                $charts[$indexName] = [
                    'chart' => $chart,
                    'counts' => $counts
                ];
            }
            $tables[$tableName]['charts'] = $charts;
        }

        return $this->render('@SurvosPixie/pixie/graphs.html.twig', [
            'pixieCode' => $pixieCode,
//            'kv' => $kv, // avoidable?/
            'tables' => $tables,
            'filename' => $kv->getFilename()
        ]);

    }

    #[Route('/import/{pixieCode}', name: 'pixie_import')]
    public function import(PixieService             $pixieService,
                           PixieImportService       $pixieImportService,
                           string                   $pixieCode,
                           #[MapQueryParameter] int $limit = 0,
    ): Response
    {
        $purgeFirst = $pixieImportService->purgeBeforeImport;
        $pixie = $pixieService->getStorageBox(
            $pixieCode,
            destroy: $purgeFirst,
            createFromConfig: true
        );
//        dd($pixie->getTables(), $purgeFirst);

        $pixieImportService->import($pixieCode, limit: $limit, kv: $pixie, overwrite: true);
        return $this->redirectToRoute('pixie_homepage', [
            'pixieCode' => $pixieCode
        ]);
    }
}
