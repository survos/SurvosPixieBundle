<?php

namespace Survos\PixieBundle\Command;

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
        #[Autowire('%env(SITE_BASE_URL)%')] private string $baseUrl,
        #[Autowire('%kernel.enabled_locales%')] private array $enabledLocales,
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private SerializerInterface $serializer,
        private EventDispatcherInterface $eventDispatcher,

        private ?MeiliService $meiliService = null,
        private ?SluggerInterface $asciiSlugger = null,
    )
    {

        parent::__construct();
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
        if (is_null($reset)) {
            $reset = true;
        }

        if (!$this->meiliService) {

            $io->error("Run composer require survos/api-grid-bundle");
            return self::FAILURE;
        }

        if ($translations) {
            $io->error("bin/console pixie:translation --index $configCode");
            return self::SUCCESS;
        }

        $this->initialized = true;
        $kv = $pixieService->getStorageBox($configCode, $subCode);
        $pixieDbName = $pixieService->getPixieFilename($configCode, $subCode);
//        assert(realpath($pixieDbName) == $kv->getFilename(), "$pixieDbName <> " . $kv->getFilename());
        $io->title("Reading $pixieDbName");
        $config = $pixieService->getConfig($configCode);

        if ($tableFilter) {
            assert($kv->tableExists($tableFilter), "Missing table $tableFilter: \n".implode("\n", $kv->getTableNames()));
        }


        $recordsToWrite=[];
        $summary = [];
        // now iterate
        foreach ($kv->getTables() as $tableName => $table) {
            // maybe someday we'll store them and use meili for lookup
            if ($tableName === PixieInterface::PIXIE_STRING_TABLE) {
                continue;
            }
            if ($tableFilter && ($tableName <> $tableFilter)) {
                continue;
            }

            if ($kv->count($tableName) == 0) {
                $this->io()->warning("Skipping $tableName (no records)");
                continue;
            }

            $indexName = $this->meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($configCode, $subCode, $tableName));
            $io->title($indexName);
            $summary[$indexName] = [
                'records' => 0,
                'reset' => 'no',
                'url' => null
            ];

            if ($reset) {
                $summary[$indexName]['reset'] = 'yes';
                $this->meiliService->reset($indexName);
            }

            $index = $this->configureIndex($config, $indexName, $tableName);
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

            // yikes, we need to configure all facets unless we have a different index for each table

            $progressBar = new ProgressBar($io, $kv->count($tableName));
            $primaryKey = 'pixie_key'; // $kv->getPrimaryKey($tableName); // ??
            $count = 0;
            $batchCount = 0;

            // first, fetch all the translations



//            $transKv = $this->pixieService->getStorageBox($pixieCode, $this->pixieService->getPixieFilename());
//            $transKv = $this->translationService->getTranslationStorageBox($pixieCode);
//            $tKvConfig = $tKv->getConfig();

            $iKv = $this->eventDispatcher->dispatch(new StorageBoxEvent(
                $configCode,
                mode: PixieInterface::PIXIE_IMAGE_SUFFIX,
                tags: ['fetch'] //??
            ))->getStorageBox();


            foreach ($kv->iterate($tableName) as $itemKey=>$row) {

                $data = $row->getData();
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
                $data->pixie_key = $this->asciiSlugger->slug($row->getKey())->toString();

                // or add the table_name to the key if multiple tables exist in one index.
//                $data->pixie_key = $this->asciiSlugger->slug(sprintf("%s_%s", $tableName, $row->getKey()))->toString();

                $data->coreId = $tableName;
                $data->table = $tableName;
                $data->rp = [
                    'pixieCode' =>  $configCode,
                    'tableName'  =>  $tableName,
                    'key'       =>  $row->getKey(),
                ];
                SurvosUtils::cleanNullsOfObject($data);
                // argh, sqlite thing for arrays
                foreach ($data as $k => $v) {
                    if (is_string($v) && strlen($v) && json_validate($v)) {
                        $data->{$k} = json_decode($v);
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
//                if (++$batchCount >= $batchSize) {
//                    $batchCount = 0;
//                    $index->addDocuments($recordsToWrite);
//                    dd($recordsToWrite);
//                    $recordsToWrite = [];
//                }

                if ($batchSize && (($progress = $progressBar->getProgress()) % $batchSize) === 0) {
                    $task = $index->addDocuments($recordsToWrite, $primaryKey);
                    // wait for the first record, so we fail early and catch the error, e.g. meili down, no index, etc.
                    if ($wait || !$progress) {
      //                $this->io->writeln("Flushing " . count($records));
//                        ($tableName=='obj') && dd($recordsToWrite);
                        $results = $this->meiliService->waitForTask($task, dataToDump: $recordsToWrite);
                    } else {
//                        dump($task, count($recordsToWrite), $primaryKey);
                    }
                    $recordsToWrite = [];
                }
                $progressBar->advance();
                if ($limit && (++$count >= $limit)) {
                    break;
                }
            }
            $progressBar->finish();

            try {
                $task = $index->addDocuments($recordsToWrite, $primaryKey);
                $recordsToWrite = [];
            } catch (\Exception $e) {
                dd($recordsToWrite, $e->getMessage());
            }
            $this->meiliService->waitForTask($task);

            // wait, so we can update owner
            // export property counts to kv
            $stats = $index->stats();
            assert(!$stats['isIndexing']);
            unset($stats['isIndexing']);
//            dd($stats, $index->getSettings());
            $io->success($stats['numberOfDocuments'] . " $configCode.$tableName documents");
            $summary[$indexName]['records'] = $stats['numberOfDocuments'];
            $this->eventDispatcher->dispatch(new IndexEvent($configCode,
                $subCode,
                $kv->getFilename(),
                $tableName, $stats));

            if ($this->io()->isVerbose()) {
                $table = new Table($this->io());
                $table->setHeaders(['attributes', 'value']);
//            $io->write(json_encode($stats, JSON_PRETTY_PRINT));
                foreach ($index->getSettings() as $var=>$value) {
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

            $homeUrl =  str_replace('https://', 'https://' . $locale . '.', $this->baseUrl) . "/$configCode/browse/$tableName";
            $summary[$indexName]['url'] = $homeUrl;

        }

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config

        $table = new Table($this->io()->output());
        foreach ($summary as $indexName=>$data) {
            $table->addRow([$indexName, ...$data]);
        }
        $table->render();

        $io->success(sprintf("%s success %s %s",  $this->getName(), $configCode, $pixieDbName));

        return self::SUCCESS;
    }

    private function configureIndex(Config $config, string $indexName, ?string $tableName=null): Indexes
    {

        $primaryKey = 'pixie_key';
        $index = $this->meiliService->getIndex($indexName, $primaryKey);
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

        $results = $index->updateSettings($settings = [
            'displayedAttributes' => ['*'],
            'searchableAttributes' => $searchableAttrs,
// when v10 is installed!!
            'localizedAttributes' => $localizedAttributes,
            'filterableAttributes' => $filterable, //  $this->datatableService->getFieldsWithAttribute($settings, 'browsable'),
            'sortableAttributes' => $sortable, // this->datatableService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
        "sortFacetValuesBy" => ["*" => "count"],
        "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
    ],
            ]);
//        dd($results, $settings);
        // wait until the index is set up.
//        $stats = $this->meiliService->waitUntilFinished($index);
        return $index;
    }



}
