<?php

namespace Survos\PixieBundle\Controller;

use App\Entity\Core;
use App\Entity\Instance;
use League\Csv\Reader;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\Property;
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
        private ?UrlGeneratorInterface  $urlGenerator=null,
        private ?MessageBusInterface $bus=null,
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

    #[Route('/property/{pixieCode}/{tableName}/{propertyCode}', name: 'pixie_show_property', requirements: ['key' => '.+'])]
    public function show_property(
        Request                      $request,
        string                       $pixieCode,
        string                       $tableName,
        string                       $propertyCode,
        #[MapQueryParameter] int     $limit = 50
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $kv->select($tableName);
        $conf = $this->pixieService->getConfig($pixieCode);
        $counts = $this->getCounts($kv, $tableName, $limit);
        // @hack, we can do better!
        foreach ($kv->getTable($tableName)->getProperties() as $property) {
            if ($propertyCode == $property->getCode()) {
                break;
            }
        }

        $chart = $this->getChartData($property, $tableName, $kv, limit:  $limit);
//        assert($chart, "no chart data for $tableName $property");
        return $this->render('@SurvosPixie/pixie/property.html.twig', [
            'kv' => $kv,
            'property' => $property,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'chart' => $chart
        ]);

    }

    #[Route('/share/{pixieCode}/{tableName}/{key}', name: 'pixie_share_item', requirements: ['key' => '.+'], options: ['expose' => true])]
    public function share(Request $request,
                          string                       $pixieCode,
                          string                       $tableName,
                          string                       $key,
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $item =  $kv->get($key, $tableName);
        return $this->render('@SurvosPixie/pixie/share.html.twig', [
            'key' => $key,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'row' => $item,
            'item' => $item, // redundant! use row for data!

        ]);
    }

    #[Route('/show/{pixieCode}/{tableName}/{key}', name: 'pixie_show_record', requirements: ['key' => '.+'], options: ['expose' => true])]
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
        if (is_numeric($key) && ((int)$key <= 0)) {
            $item = $kv->getByIndex((int)$key, $tableName);
            return $this->redirectToRoute('pixie_show_record', ['pixieCode' => $pixieCode, 'tableName' => $tableName, 'key' => $item->getKey()]);
            // get the first record, we can use this for a thumbnail too.
        } else {
            $item = $kv->get($key, $tableName);
        }
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
        $config = $this->pixieService->getConfig($pixieCode);

        // quick hack for groups
        $groups = [];
        foreach ($table->getTranslatable() as $key) {
            $groups[$group='text'][] = $key;
            $reverse[$key] = $group;
        }
        foreach ($table->getProperties() as $property) {
            $settings = $property->getSettings();
            if (!$group = $settings['g']??null) {
                $index = $property->getIndex();
                if ($index) {
                    $group = $property->getIndex();
                }
            }
            $groups[$group][] = $property->getCode();
            $reverse[$property->getCode()] = $group;
        }

        foreach (array_keys($item->getData(true)) as $key) {
            if (!array_key_exists($key, $reverse)) {
                $groups['attr'][] = $key;
            }
        }
//        dd($reverse, $groups['attr'], $groups);
//
//        dd($item->getData(), $config->getTable($tableName)->getProperties());
//        $core = new Core();
//        $instance = (new Instance($core, $item->getKey()));

//        dd($workflow, $table);
//        $item = (array)$item;
        return $this->render('@SurvosPixie/pixie/show.html.twig', [
            'instance' => $instance??null,
            'kv' => $kv,
            'workflowEnabled' => (bool)$workflow, // comes from config
            'workflow' => $workflow,
            'key' => $key,
            'tableName' => $tableName,
            'pixieCode' => $pixieCode,
            'row' => $item,
            'item' => $item, // redundant! use row for data!
            'columns' => array_keys((array)$item),
            'groups' => $groups
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
        // see SearchController for meili and the public-facing browse
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

    private function getCounts(StorageBox $kv, string $tableName=null, int $limit=0): array
    {
        $counts = [];
        foreach ($kv->getIndexes($tableName) as $indexName) {
            $counts[$indexName] = $kv->getCounts($indexName, $tableName, $limit);
        }
        return $counts;


    }

    #[Route('/_search', name: 'pixie_config_search')]
//    #[Template()]
    public function search_pixies(
        #[MapQueryParameter] int $limit = 50,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] string $pixieCode = ''
    ): array|Response
    {
        $configs = $this->pixieService->getConfigFiles($q, limit: $limit, pixieCode: $pixieCode);
        // cache candidate!
        $tables = [];
        if ($pixieCode) {
        }
        foreach ($configs as $pixieCode => $config) {
            $kv = $this->pixieService->getStorageBox($pixieCode);
            foreach ($kv->getTables() as $tableName => $table) {
                // how many items in the table
                $tables[$pixieCode][$tableName]['count'] = $kv->count($tableName);
                // the key indexes
                $indexCounts = $this->getCounts($kv, $tableName, $limit);
                $tables[$pixieCode][$tableName]['indexes'] = $indexCounts;
//                foreach ($kv->getIndexes($tableName) as $indexName) {
//                    $tables[$pixieCode][$tableName]['indexes'][$indexName] = $kv->getCounts($indexName, $tableName);
//                }
            }
        }

        return $this->render('@SurvosPixie/pixie/_search_results.html.twig', [
            'configs' => $configs,
                'dir' => $this->pixieService->getConfigDir(),
                'tables' => $tables,

            ]
        );

    }

    #[Route('/', name: 'pixie_browse_configs')]
//    #[Template()]
    public function pixies(
        #[MapQueryParameter] int $limit = 50
    ): array|Response
    {

        return $this->render('@SurvosPixie/pixie/index.html.twig', [
            'dir' => $this->pixieService->getConfigDir(),
        ]);
//        return $this->render(, );
    }

    #[Route('/{pixieCode}/home', name: 'pixie_homepage')]
    #[Route('/{pixieCode}', name: 'pixie_overview')]
    public function overview(
        string                   $pixieCode,
        #[MapQueryParameter] int $limit = 100
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
        return $this->render('@SurvosPixie/pixie/overview.html.twig', [
            'limit' => $limit,
            'kv' => $kv,
            'tables' => $tables,
            'pixieCode' => $pixieCode,
            'config' => $this->pixieService->getConfig($pixieCode)
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

    private function getChartData(Property $property, string $tableName, StorageBox $kv, int $limit=100): ?array
    {

        if (!$indexName = $property->getIndex()) {
            return null;
        }
        // primary is required and maybe min/max?
        if ($indexName === 'PRIMARY') {
            return null;
        }
        $chartBuilder = $this->chartBuilder;
        $indexName = $property->getCode();
        $labels = [];
        $values = [];

        $counts = $kv->getCounts($indexName, $tableName, $limit);
        if (count($counts) === 0) {
            return null;
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

        return [
            'chart' => $chart,
            'counts' => $counts
        ];

    }

    #[Route('/table/{pixieCode}/{tableName}', name: 'pixie_table')]
    public function home(
        string                   $pixieCode,
        string                   $tableName,
        #[MapQueryParameter] int $limit = 25
    ): Response
    {
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        assert(file_exists($pixieFilename));
        $kv = $this->pixieService->getStorageBox($pixieCode);
        {
            $count = $kv->count($tableName);
//            dd($tableName, $count);
            $tableData = [
                'first' => $kv->iterate($tableName)->current(),
                'count' => $kv->count($tableName) // we could cache this someday, like ->closeWithCounts()
            ];

            $charts = [];
            $table = $kv->getTable($tableName);
            $tableSchema = $kv->inspectSchema()[$tableName];
            foreach ($table->getProperties() as $property) {
//                dd($tableSchema, $property, $table->getProperties());
                try {
                    $chartData = $this->getChartData($property, $tableName, $kv, limit: $limit);
                    if ($chartData) {
                        $charts[$property->getCode()] = $chartData;
                    }
                } catch (\Exception $e) {
                    // probably a migration is needed.
                }
            }
            $tableData['charts'] = $charts;
        }
//        dd($tables);

        return $this->render('@SurvosPixie/pixie/graphs.html.twig', [
            'pixieCode' => $pixieCode,
            'tableName'=>$tableName,
//            'kv' => $kv, // avoidable?/
            'tableData' => $tableData
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
