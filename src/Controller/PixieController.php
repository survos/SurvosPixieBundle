<?php

namespace Survos\PixieBundle\Controller;

use League\Csv\Reader;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/pixie')]
class PixieController extends AbstractController
{

    public function __construct(
        private ParameterBagInterface $bag,
        private PixieService $pixieService
    ) {

    }

    private function getPixieConf(string $pixieCode, bool $throwIfMissing=true): ?string
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

    #[Route('/show/{pixieCode}/{tableName}/{key}', name: 'pixie_show_record')]
    public function show_record(
        string $pixieCode,
        string $tableName,
        string $key,
        #[MapQueryParameter] ?string $index=null,
        #[MapQueryParameter] ?string $value=null,
        #[MapQueryParameter] int $limit = 5
    ): Response
    {
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $kv->select($tableName);
        $row = $kv->get($key, $tableName);
        if (!$row) {
            throw new NotFoundHttpException("No key $key in $tableName / $pixieCode");
        }
        $row = (array)$row;
        return $this->render('@SurvosPixie/pixie/show.html.twig', [
            'row' => $row,
            'columns' => array_keys($row),
            ]);

    }

    #[Route('/browse/{pixieCode}/{tableName}', name: 'pixie_browse')]
    public function browse(
                         string $pixieCode,
                         string $tableName,
                         #[MapQueryParameter] ?string $index=null,
                         #[MapQueryParameter] ?string $value=null,
                         #[MapQueryParameter] int $limit = 5
    ): Response
    {
        // need to handle extension
        $filename = $this->pixieService->getPixieFilename($pixieCode);
        $kv = $this->pixieService->getStorageBox($filename);
        $where = [];
        if ($index) {
            $where[$index] = $value?:null;
        }
        $kv->select($tableName);
        $iterator = $kv->iterate($tableName, $where);

        if ($firstRow = $iterator->current()) {
            $columns = array_keys($firstRow);
        } else {
            $columns = ['value'];
        }
        array_unshift($columns, 'key');
        $iterator->rewind();
//        foreach ($kv->iterate($tableName, $where) as $row) {
//            dd($row);
//        }
        // see kaggle for inspiration, https://www.kaggle.com/datasets/shivamb/real-or-fake-fake-jobposting-prediction/data
        return $this->render('@SurvosPixie/pixie/browse.html.twig', [
            'pixieCode' => $pixieCode,
'tableName' => $tableName,
//            'kv' => $kv, // avoidable?/
            'iterator' => $firstRow ? $iterator : [],
            'keyName' => $kv->getPrimaryKey(),
            'columns' => $columns,
            'filename' => $kv->getFilename(),
        ]);

    }

    #[Route('/{pixieCode}', name: 'pixie_homepage')]
    public function home(ChartBuilderInterface $chartBuilder,
                         string $pixieCode,
    #[MapQueryParameter] int $limit = 5
    ): Response
    {
        $firstRecords = [];
        $charts = [];
        $tables = [];
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        $kv = $this->pixieService->getStorageBox($pixieFilename);
        foreach ($kv->getTables() as $tableName) {
            $count = $kv->count($tableName);
//            dd($tableName, $count);
            $tables[$tableName] = [
                'first' => $kv->iterate($tableName)->current()
            ];

            $charts = [];
            foreach ($kv->getIndexes($tableName) as $indexName) {
                $labels =  [];
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
                        rand(0,255),
                        rand(0,255),
                        rand(0,255)
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
                    'chart'=> $chart,
                    'counts' => $counts
                    ];
            }
            $tables[$tableName]['charts'] = $charts;
        }

        return $this->render('@SurvosPixie/pixie/graphs.html.twig', [
            'pixieCode' => $pixieCode,
//            'kv' => $kv, // avoidable?/
        'tables' => $tables,
            'filename' => $kv->getFilename(),
            'firstRecords' => $firstRecords
        ]);

    }
    #[Route('/import/{pixieCode}', name: 'pixie_import')]
    public function import(PixieService             $pixieService,
                           PixieImportService       $pixieImportService,
                           string                   $pixieCode,
                           #[MapQueryParameter] int $limit = 0,
    ): Response
    {
        // get the conf file from the configured directories (from the bundle)

        $config = $pixieService->getConfig($pixieCode);
        // cache wget "https://github.com/MuseumofModernArt/collection/raw/main/Artists.csv"   ?
        $pixieImportService->import($config, $pixieCode, limit: $limit);
        return $this->redirectToRoute('pixie_homepage', [
            'pixieCode' => $pixieCode
        ]);

        dd();




        $tables = ['tables'];
        foreach ($tables as $tableName => $tableData) {
            $tablesToCreate[$tableName] = $tableData['indexes'];
        }
        $kv = $pixieService->getStorageBox($pixieDbName, $tablesToCreate);
//        dd($pixieDbName, $configFilename, $tablesToCreate);

        foreach ($tables as $tableName => $tableData) {
        $kv->map($tableData['rules'], [$tableName]);
        $kv->select($tableName);

            $fn = $this->dataDir . '/moma/' . ucfirst($tableName) . 's.csv';
            assert(file_exists($fn), $fn);
            // pixiedb? phixy.db?
            $csv = Reader::createFromPath($fn, 'r');
            $csv->setHeaderOffset(0); //set the CSV header offset

            $headers = $kv->mapHeader($csv->getHeader());
            $kv->beginTransaction();
            assert(count($headers) == count(array_unique($headers)), json_encode($headers));
            foreach ($csv->getRecords($headers) as $idx => $row) {
                $kv->set($row);
//                if ($idx > 100) break;
//            dd($kv->get($row['id']));
//            dump($row); break;
            }
            $kv->commit();
            foreach ($kv->iterate() as $key => $row) {
                dump($key, $row); break;
            }
        }

        return $this->redirectToRoute('pixie_homepage');
    }
}
