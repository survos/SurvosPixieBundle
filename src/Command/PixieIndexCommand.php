<?php

namespace Survos\PixieBundle\Command;

use App\Metadata\ITableAndKeys;
use Meilisearch\Client;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\PixieBundle\Service\SqliteService;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\ApiGrid\Service\MeiliService;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Event\IndexEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieTranslationService;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Intl\Locales;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:index', 'create a Meili index"')]
final class PixieIndexCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        #[Autowire('%env(SITE_BASE_URL)%')] private string    $baseUrl,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales,
        private LoggerInterface                               $logger,
        private ParameterBagInterface                         $bag,
        private readonly PixieService                         $pixieService,
        private SerializerInterface                           $serializer,
        private EventDispatcherInterface                      $eventDispatcher,
        private EntityManagerInterface                        $pixieEntityManager,
        private RowRepository                                 $rowRepository,
        private SqliteService                                 $sqliteService, private readonly OriginalImageRepository $originalImageRepository,

        private ?MeiliService                                 $meiliService = null,
        private ?SluggerInterface                             $asciiSlugger = null,
        private array                                         $tasks = [], //
        private ?Client                                       $meiliClient=null
    )
    {

        parent::__construct();
        $this->meiliClient = $this->meiliService->getMeiliClient();
    }

    public function __invoke(
        IO                                                                             $io,
        PixieService                                                                   $pixieService,
        PixieImportService                                                             $pixieImportService,
        #[Argument(description: 'config code')] ?string                                $configCode,
        #[Argument(description: 'sub code, e.g. musdig inst id')] ?string              $subCode=null,
        #[Option('dir', description: 'dir to pixie')] ?string                           $dir=null,
        #[Option('table', description: 'table name(s?), all if not set')] ?string       $tableFilter=null,
//        #[Option(name: 'trans', description: 'fetch the translation strings')] bool $addTranslations=false,
        #[Option(description: "reset the meili index")] ?bool                          $reset=null,
//        #[Option(name:'trans-table', description: "use the translation table instead of _trans")] ?bool $transTable=null,
        #[Option(description: "wait for tasks to finish")] ?bool                          $wait=null,
        #[Option(description: "populate translations first (via pixie:trans)")] ?bool                          $translations=null,
        #[Option(description: "max number of records per table to export")] int        $limit = 0,
        #[Option(description: "extra data (YAML), e.g. --extra=[core:obj]")] string    $extra = '',
        #[Option('batch', description: "max number of records to batch to meili")] int $batchSize = 1000,

    ): int
    {
        $configCode ??= getenv('PIXIE_CODE');
        $this->pixieService->selectConfig($configCode);
//        $pixieEm = $this->sqliteService->setPixieEntityManager($configCode);
        $reset ??= true;

        if (!$this->meiliService) {
            $io->error("Run composer require survos/meili-bundle");
            return self::FAILURE;
        }

        if ($translations) {
            $io->error("bin/console pixie:translation --index $configCode");
            return self::SUCCESS;
        }

        $this->initialized = true;
        $this->pixieService->selectConfig($configCode);
        $config = $pixieService->selectConfig($configCode);
        $io->title("using pixie EM $configCode");


        // all rows, too problematic with facets and summaries
//        $this->indexRowTable($io, $recordsToWrite, $batchSize, $index, $primaryKey, $wait, $limit);

        $summary = [];
        $owner = $config->getOwner(); // the owner record in pixie EM
        $recordsToWrite=[];
        foreach ($owner->getCores()->filter(
            fn(Core $core) => $core->getRowCount() > 0
        ) as $core) {

            $tableName = $core->getCode();
            // maybe someday we'll store them and use meili for lookup
            if ($tableName === PixieInterface::PIXIE_STRING_TABLE) {
                continue;
            }
            if ($tableFilter && ($tableName <> $tableFilter)) {
                continue;
            }

            $indexName = $this->meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($configCode, $subCode, $tableName));
            $io->title($indexName);
            $summary[$indexName] = [
                'records' => 0,
                'reset' => 'no',
                'url' => null
            ];

            $indexApi = $this->meiliClient->index($indexName);
//            $client = $this->meiliService->getMeiliClient();
            if ($reset) {
                $this->addTask($indexApi->delete($indexName));
            }
            $this->configureIndex($config, $indexApi, $tableName);

            $primaryKey = 'pixie_key'; // $kv->getPrimaryKey($tableName); // ??

            $iQb = $this->originalImageRepository->createQueryBuilder('i');
            $this->indexCoreRows($core, $indexApi, $configCode, $batchSize, $limit);
            try {
                if (count($recordsToWrite)) {
                    $this->addTask($indexApi->addDocuments($recordsToWrite, $primaryKey), "final dispatch", $recordsToWrite);
                    $recordsToWrite = [];
                }
            } catch (\Exception $e) {
                dd($recordsToWrite, $e->getMessage());
            }
//            $this->meiliService->waitForTask($task);

            // wait, so we can update owner, @todo: move to async task
            // export property counts to kv
//            $taskEndpoint = $this->meiliClient->tasks;
            foreach ($this->tasks as $task) {
                $taskUid = $task->taskUid;
                // @todo: add task model
                $status = $this->meiliClient->waitForTask($taskUid);
                dump($status);
//                $status = $this->meiliClient->getTask();
            }


            // dispatch a message that updates the database with meili
            // this is synchronous!
                $stats = $indexApi->stats();
                assert(!$stats['isIndexing'], json_encode($stats));
                unset($stats['isIndexing']);
//            dd($stats, $index->getSettings());
                $io->success($stats['numberOfDocuments'] . " $configCode.$tableName documents");
                $summary[$indexName]['records'] = $stats['numberOfDocuments'];
//                dd($stats, $core, $core->getCoreCode(), $index->getUid());
                $this->eventDispatcher->dispatch(new IndexEvent($configCode,
                        $subCode,
                        $tableName,
                        stats: $stats
//            $iKv->count(PixieInterface::IMAGE_TABLE)
                    )
                );

//            }

            if ($this->io()->isVerbose()) {
                $table = new Table($this->io());
                $table->setHeaders(['attributes', 'value']);
//            $io->write(json_encode($stats, JSON_PRETTY_PRINT));
                foreach ($indexApi->getSettings() as $var=>$value) {
                    if (str_contains($var, 'Attributes')) {
                        $table->addRow([$var, json_encode($value)]);
                    }
                }
                $table->render();
            }
            if ($io->isVerbose()) {
//                $io->write(json_encode($index->getSettings(), JSON_PRETTY_PRINT));
            }

//            $filename = $pixieCode . '-' . $tableName.'.json';
//            file_put_contents($filename, $this->serializer->serialize($recordsToWrite, 'json'));
//            $io->success(count($recordsToWrite) . " indexed meili $indexName");

            $locale = $config->getSource()->locale;

            $homeUrl =  str_replace('https://', 'https://' . $locale . '.', $this->baseUrl) . "/$configCode/$tableName";
            $summary[$indexName]['url'] = $homeUrl;

        }

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Entity databases go in datadir, not with their source? Or defined in the config

        $table = new Table($this->io()->output());
        foreach ($summary as $indexName=>$data) {
            $table->addRow([$indexName, ...$data]);
        }
        $table->render();

        $io->success(sprintf("%s success %s",  $this->getName(), $configCode));

        return self::SUCCESS;
    }

    private function configureIndex(Config $config,
                                    Indexes $indexApi,
                                    ?string $tableName=null): Indexes
    {
//        $primaryKey = 'pixie_key';
        $filterable = ['table'];
        $sortable = ['id']; // hack

        // foreach ($config->getTables() as $table)
        $table = $config->getTable($tableName);
        {

            foreach ($table->getProperties() as $property) {
                $code = $property->getCode();
                // the table pk is renamed to {tableName}_{pk}
                if ($property->getIndex() === 'PRIMARY') {
                    // skip for now
                } elseif ($property->getIndex() == 'INDEX') {
                    $filterable[] = $code;
                    $sortable[] = $code;
                }
            }
        }
        $searchableAttrs = [];
        $localizedAttributes = [];
        foreach ($this->enabledLocales as $locale) {
            $localizedAttributes[] = ['locales' => [$locale],
                'attributePatterns' => [sprintf('%s.%s.*',PixieInterface::TRANSLATED_STRINGS, $locale)]];
            foreach ($table->getTranslatable() as $property) {
                $searchableAttrs[] = sprintf('%s.%s.%s',
                    PixieInterface::TRANSLATED_STRINGS, $locale, $property);
            }
        }

//        $localizedAttributes[] = ['locales' => [], 'attributePatterns' => ['*']];

        $this->addTask($indexApi->updateSettings($settings = [
            'displayedAttributes' => ['*'],
            'searchableAttributes' => $searchableAttrs,
            'localizedAttributes' => $localizedAttributes,
            'filterableAttributes' => $filterable, //  $this->datatableService->getFieldsWithAttribute($settings, 'browsable'),
            'sortableAttributes' => $sortable, // this->datatableService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
        "sortFacetValuesBy" => ["*" => "count"],
        "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
    ],
            ]), "updateSettings", $settings);
        // wait until the index is set up.
//        $stats = $this->meiliService->waitUntilFinished($index);

        // add the embedder

        return $indexApi;
    }

    /**
     * @param IO $io
     * @param array $recordsToWrite
     * @param int $batchSize
     * @param Indexes $index
     * @param string $primaryKey
     * @param bool|null $wait
     * @param int $limit
     * @return int
     * @throws \Exception
     */
    private function indexRowTableAllAsOneNotUsed(IO $io, array $recordsToWrite, int $batchSize, Indexes $index, string $primaryKey, ?bool $wait, int $limit): int
    {
        $primaryKey = 'pixie_key';

        $tableName = 'obj';
        $indexName = $configCode . '_' . $tableName;

        if ($reset) {
            $summary[$indexName]['reset'] = 'yes';
            $this->meiliService->reset($indexName);
        }

        // sync call
//        $index = $this->meiliService->getIndex($indexName, $primaryKey);
        $index = $this->configureIndex($config, $index, $indexName, $tableName);

        $qb = $this->rowRepository->createQueryBuilder('row');
        $progressBar = SurvosUtils::createProgressBar($io, $this->rowRepository->count());

        /** @var Row $row */
        foreach ($progressBar->iterate($qb->getQuery()->toIterable()) as $row) {

            $rowData = $this->serializer->normalize($row, 'array', [
                'groups' => ['row.read', 'row.images']
            ]);
            // merge the entity data with the $data, ignore the raw in the index

            $indexRow = array_merge($row->getData() ?? [], $rowData);
//            dd($rowData, $row, $row->getData(), indexRow: $indexRow);
//        foreach ($qb->getQuery()->toIterable() as $row) {
            $indexRow['pixie_key'] = $row->getId(); // $this->asciiSlugger->slug($row->getKey())->toString();

            $recordsToWrite[] = $indexRow;
            assert($batchSize > 0);
            if ($batchSize && (count($recordsToWrite) === $batchSize)) {
                $task = $index->addDocuments($recordsToWrite, $primaryKey);
                $this->addTask($task, "Progress: " . $progressBar->getProgress());
                // wait for the first record, so we fail early and catch the error, e.g. meili down, no index, etc.
//                if ($wait || !$progress) {
//                    //                $this->io->writeln("Flushing " . count($records));
////                        ($tableName=='obj') && dd($recordsToWrite);
//                    $results = $this->meiliService->waitForTask($task, dataToDump: $recordsToWrite);
//
//                } else {
////                        dump($task, count($recordsToWrite), $primaryKey);
//                }
                $recordsToWrite = [];
            }
            if ($limit && ($progressBar->getProgress() >= $limit)) {
                break;
            }
        }
        $progressBar->finish();
        $this->io()->writeln(".");
        return self::SUCCESS;
    }

    private function addTask(array $task, ?string $message=null, mixed $context=null): void
    {
        $this->tasks[] = (object)$task;
//        $x = $this->meiliClient->waitForTask($task['taskUid']);
//        if ($task['status'] == 'failed') {
//            // @todo: check for cases where we need the index, e.g. addDocuments
//            if ($task['error']['code'] !== 'index_not_found') {
//                dd($x, $message, $context);
//            }
//        }
    }

    /**
     * @param mixed $core
     * @param $kv
     * @param string $tableName
     * @param $iKv
     * @param false|array|string $configCode
     * @param array $recordsToWrite
     * @param int $batchSize
     * @param ProgressBar $progressBar
     * @param Indexes $index
     * @param string $primaryKey
     * @param bool|null $wait
     * @param mixed $task
     * @param int $limit
     * @param int $count
     * @return array
     * @throws \Exception
     */
    public function indexCoreRows(Core $core, Indexes $index, string $configCode,
                                  int $batchSize, int $limit): int
    {
        $total = 0;
        $primaryKey = $index->getPrimaryKey();
        $batchCount = 0;

//        $count = $this->rowRepository->count(['core' => $core]);
        $qb = $this->rowRepository->createQueryBuilder('row')
            ->join('row.core', 'core')
            ->andWhere('core.id = :core')
            ->setParameter('core', $core->getId());
        $query = $qb->getQuery();
        if ($limit) {
            $query->setMaxResults($limit);
        }

        $progressBar = new ProgressBar($this->io(), $core->getRowCount());
        $progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n%current%/%max% [%bar%] %percent:3s%%\n🏁  %estimated:-21s% %memory:21s%"
        );

            $rows = $query->toIterable();
        /** @var Row $row */
        foreach ($progressBar->iterate($rows) as $row) {

            $data = (object) $row->getData()??$row->getRaw(); // this _should_ be the final version after polish!
            // hack for testing
            // could also be id-within-core if each core has its own index
            $data->pixie_key = $row->getId();
//            dd($data, $row);
            if (0)
            if ($data->images_count ?? 0) {
                foreach ($data->images as $image) {
                    $iKvItem = $iKv->get($image->code);
//                        $tableName=='obj' && dd($data);
                    // or merge?   ugh.
                    $data->{ITableAndKeys::RESIZED_KEY}[] = $iKvItem->resized() ?? [];
//                        dd($iKvItem, $data);
                }
            }
//                ($itemKey=='638384caab8f2aac') && dump($tableName, $row, $row->getData(true)[PixieInterface::TRANSLATED_STRINGS]??null);

//                $this->logger->info($row->getKey() . "\n\n" . json_encode($row, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));
            // hack
//                $lang = $row->expected_language()??$config->getSource()->locale;

            // maybe someday, not worth it now to refactor
//                if ($transTable) {
////                    $this->
//                }

//                dd($lang, $row);
//                foreach ($table->getTranslatable() as $translatableProperty) {
//                    $toTranslate[] = $row->{$translatableProperty}();
//                }
//
            // @todo: optimize fetches
            // moved to pixie:translation, add it in the pixie itself
//                if ($addTranslations) {
//                    foreach ($table->getTranslatable() as $translatableProperty) {
//                        $data->_translations=[];
//                        if ($textToTranslate = $row->{$translatableProperty}()) {
//                            $toTranslate[] = $textToTranslate;
//                            $key = PixieTranslationService::calculateHash($textToTranslate, $lang);
//                            // @todo: batch keys with "in"
//                            $translations = $transKv->iterate(where: ['hash' => $key]);
////                            dd(iterator_to_array($translations), $textToTranslate,  $key, $transKv->getFilename());
//                            foreach ($translations as $translation) {
//                                $data->_translations[$translation->target()][$translatableProperty] = $translation->text();;
//                            }
//                        }
//                    }
////                    dd($data, $data->_translations);
//                    unset($data->translations);
//                }
            // this is the data we got when inserting the original text
//                dd($data);

//                if (array_key_exists('keyedTranslations', $data)) {
//                    $data['_translations'] = $data['keyedTranslations'];
//                    $data['targetLocales'] = array_keys($data['_translations']);
////                unset($data['keyedTranslations']);
//                }

            // slugify the pixie key, which might be a filename like obj1.jpg
            $data->pixie_key = $row->getId(); // $this->asciiSlugger->slug($row->getId())->toString();

            // or add the table_name to the key if multiple tables exist in one index.
//                $data->pixie_key = $this->asciiSlugger->slug(sprintf("%s_%s", $tableName, $row->getKey()))->toString();

            $tableName = $core->getCode(); // a mess. @todo: cleanup!
//            $data->coreId = $tableName;
            $data->table = $tableName;
            $data->rp = [
                'pixieCode' => $configCode,
                'tableName' => $tableName,
                'key' => $row->getKey(),
            ];
            SurvosUtils::cleanNullsOfObject($data);
            // BUT if the fields are property defined, we don't need to inspect like this.

            // argh, sqlite thing for arrays, but now everything goes through the ORM.
            foreach ($data as $k => $v) {
                if (is_string($v) && strlen($v) && json_validate($v)) {
                    $value = json_decode($v, true);
                    if (is_array($value)) {
                        dd($k, $v, $value);
                        $data->{$k} = $value;
                    }
//                        assert($data->{$k}, $k . " is empty [$v] $tableName $configCode $v" . json_encode($data, JSON_PRETTY_PRINT));
                }
            }

            // insert images

//                ($data->table=='obj') && ($data->id==266) && dd($data);
//                ($data->imageCodes??false) && dd($data);
//                ($data->imageCount??0) && dd($data);
//                ($data->imageCount??0) && dd($data, $data->images, $data->imageCodes);

            $recordsToWrite[] = $data;
//                $row->getKey() == 56185 && dd(dataToWrite: $data, row: $row;
                if (++$batchCount >= $batchSize) {
                    $batchCount = 0;
                    $index->addDocuments($recordsToWrite);
                    $recordsToWrite = [];
                }

            assert($batchSize > 0);
            $progress = $progressBar->getProgress();
//            dd($progress,$batchSize);
            if ( (($progress+1) % $batchSize) === 0) {
                $this->addTask($index->addDocuments($recordsToWrite, $primaryKey), context: [
                    'records' => $recordsToWrite,
                    'primaryKey' => $primaryKey,
                ]);
                // wait for the first record, so we fail early and catch the error, e.g. meili down, no index, etc.
//                if ($wait || !$progress) {
//                    //                $this->io->writeln("Flushing " . count($records));
////                        ($tableName=='obj') && dd($recordsToWrite);
//                    $results = $this->meiliService->waitForTask($task, dataToDump: $recordsToWrite);
//                } else {
////                        dump($task, count($recordsToWrite), $primaryKey);
//                }
                $recordsToWrite = [];
            }
        }
        if (count($recordsToWrite)) {
            $this->addTask($index->addDocuments($recordsToWrite, $primaryKey), context: [
                'records' => $recordsToWrite,
                'primaryKey' => $primaryKey,
            ]);

        }

        return $total;
    }

    private function addDocuments()
    {

    }


}
