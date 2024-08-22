<?php

namespace Survos\PixieBundle\Command;

use App\Metadata\PixieInterface;
use App\Service\TranslationService;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\ApiGrid\Service\MeiliService;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Serializer\SerializerInterface;
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
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private SerializerInterface $serializer,
        private ?MeiliService $meiliService = null,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                                          $io,
        PixieService                                          $pixieService,
        PixieImportService                                    $pixieImportService,
        #[Argument(description: 'config code')] string        $pixieCode,
        #[Option(description: 'table name(s?), all if not set')] string         $table=null,
        #[Option(description: "reset the meili index")] bool                      $reset = false,
        #[Option(description: "max number of records per table to export")] int                     $limit = 0,
        #[Option(description: "extra data (YAML), e.g. --extra=[core:obj]")] string                     $extra = '',
        #[Option('batch', description: "max number of records to batch to meili")] int                     $batchSize = 1000,

    ): int
    {
        if (!$this->meiliService) {

            $io->error("Run composer require survos/api-grid-bundle");
            return self::FAILURE;
        }
        $this->initialized = true;
        $kv = $pixieService->getStorageBox($pixieCode);
        $config = $pixieService->getConfig($pixieCode);

        $indexName = $this->meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($pixieCode));

        $io->title($indexName);
        if ($reset) {
            $this->meiliService->reset($indexName);
        }
        $index = $this->configureIndex($config, $indexName);
//            $task = $this->waitForTask($this->getMeiliClient()->createIndex($indexName, ['primaryKey' => Instance::DB_CODE_FIELD]));

        // yikes, we need to configure all facets unless we have a different index for each table
        if ($table) {
            assert($kv->tableExists($table), "Missing table $table: \n".join("\n", $kv->getTableNames()));
        }

        $recordsToWrite=[];
        $key = $key??'key';
        // now iterate
        foreach ($kv->getTables() as $tableName => $table) {
            $progressBar = new ProgressBar($io, $kv->count($tableName));
            $count = 0;
            $batchCount = 0;

            // first, fetch all the translations

            $transKv = $this->pixieService->getStorageBox(PixieInterface::PIXIE_TRANSLATION);
            $transKv->select(TranslationService::ENGINE);

            foreach ($kv->iterate($tableName) as $idx => $row) {
                $progressBar->advance();
                $data = $row->getData();

                $transKv = $this->pixieService->getStorageBox(PixieInterface::PIXIE_TRANSLATION);

//                foreach ($table->getTranslatable() as $translatableProperty) {
//                    $toTranslate[] = $row->{$translatableProperty}();
//                }
//
                // @todo: optimize fetches
                foreach ($table->getTranslatable() as $translatableProperty) {
                    if ($textToTranslate = $row->{$translatableProperty}()) {
                        $toTranslate[] = $textToTranslate;
                        $key = TranslationService::calculateHash($textToTranslate);
                        // @todo: batch keys with "in"
                        $translations = $transKv->iterate(where: ['hash' => $key]);
                        foreach ($translations as $translation) {
                            $data->_translations[$translation->target()][$translatableProperty] = $translation->text();;
                        }
                    }
                }
                // this is the data we got when inserting the original text
                unset($data->translations);
//                dd($data);

//                if (array_key_exists('keyedTranslations', $data)) {
//                    $data['_translations'] = $data['keyedTranslations'];
//                    $data['targetLocales'] = array_keys($data['_translations']);
////                unset($data['keyedTranslations']);
//                }

                $data->pixie_key = sprintf("%s_%s", $tableName, $row->getKey());
                $data->coreId = $tableName;
                $data->table = $tableName;
                $data->rp = [
                    'pixieCode' =>  $pixieCode,
                    'tableName'  =>  $tableName,
                    'key'       =>  $row->getKey(),
                ];
                $recordsToWrite[] = $data;
                if (++$batchCount >= $batchSize) {
                    $batchCount = 0;
                    $index->addDocuments($recordsToWrite);
                    $recordsToWrite = [];
                }
                if ($limit && (++$count >= $limit)) {
                    break;
                }
            }
            $progressBar->finish();

            $index->addDocuments($recordsToWrite);

//            $filename = $pixieCode . '-' . $tableName.'.json';
//            file_put_contents($filename, $this->serializer->serialize($recordsToWrite, 'json'));
//            $io->success(count($recordsToWrite) . " indexed meili $indexName");
        }

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config


        // export?
        if ($io->isVerbose()) {
            $stats = $index->stats();
            $io->write(json_encode($stats, JSON_PRETTY_PRINT));
            $io->write(json_encode($index->getSettings(), JSON_PRETTY_PRINT));
            // now what?

        }

        $io->success(sprintf("%s success %s %s",  $this->getName(), $pixieCode, $indexName));
        return self::SUCCESS;
    }

    private function configureIndex(Config $config, string $indexName): Indexes
    {

        $primaryKey = 'pixie_key';
        $index = $this->meiliService->getIndex($indexName, $primaryKey);
        $filterable = ['table'];
        $sortable = ['id']; // hack

        foreach ($config->getTables() as $table) {
            foreach ($table->getProperties() as $property) {
                $code = $property->getCode();
                // the table pk is renamed to {tableName}_{pk}
                if ($property->getIndex() === 'PRIMARY') {
                    // skip for now
                } elseif ($property->getIndex() == 'INDEX') {
                    $filterable[] = $code;
                }
            }
        }

        $results = $index->updateSettings($settings = [
            'displayedAttributes' => ['*'],
            'filterableAttributes' => $filterable, //  $this->datatableService->getFieldsWithAttribute($settings, 'browsable'),
            'sortableAttributes' => $sortable, // this->datatableService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
        "sortFacetValuesBy" => ["*" => "count"],
        "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
    ],
            ]);

//        dd($results, $settings);
        // wait until the index is set up.
        $stats = $this->meiliService->waitUntilFinished($index);
        return $index;

        dd($results);

//        $reflection = new \ReflectionClass($class);
//        $classAttributes = $reflection->getAttributes();
//        $filterAttributes = [];
//        $sortableAttributes = [];
        $settings = $this->datatableService->getSettingsFromAttributes($class);
        $primaryKey = 'id'; // default, check for is_primary));
        $idFields = $this->datatableService->getFieldsWithAttribute($settings, 'is_primary');
        if (count($idFields)) $primaryKey = $idFields[0];
//        dd($settings, $filterAttributes);
//
//        foreach ($settings as $fieldname=>$classAttributes) {
//            if ($classAttributes['browsable']) {
//                $filterAttributes[] = $fieldname;
//            }
//            if ($classAttributes['sortable']) {
//                $sortableAttributes[] = $fieldname;
//            }
//            if ($classAttributes['searchable']) {
////                $searchAttributes[] = $fieldname;
//            }
//            if ($classAttributes['is_primary']??null) {
//                $primaryKey = $fieldname;
//            }
//        }

//        $index->updateSortableAttributes($this->datatableService->getFieldsWithAttribute($settings, 'sortable'));
//        $index->updateSettings(); // could do this in one call

        $results = $index->updateSettings($settings = [
            'displayedAttributes' => ['*'],
            'filterableAttributes' => $this->datatableService->getFieldsWithAttribute($settings, 'browsable'),
            'sortableAttributes' => $this->datatableService->getFieldsWithAttribute($settings, 'sortable'),
            "faceting" => [
                "sortFacetValuesBy" => ["*" => "count"],
                "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
            ],
        ]);

        $stats = $this->meiliService->waitUntilFinished($index);
        return $index;
    }



}
