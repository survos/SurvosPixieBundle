<?php

namespace Survos\PixieBundle\Command;

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
        $table = $config->getTables()[$tableName]; // to get views, key
        $count = 0;
        $batchCount = 0;
        foreach ($config->getTables() as $table) {

            $tableName = $table->getName();

            foreach ($kv->iterate($tableName) as $idx => $row) {
                $data = $row->getData();
                $data->coreId = 'obj'; // hack $tableName;
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
            $index->addDocuments($recordsToWrite);

//            $filename = $pixieCode . '-' . $tableName.'.json';
//            file_put_contents($filename, $this->serializer->serialize($recordsToWrite, 'json'));
            $io->success(count($recordsToWrite) . " records written to meili $indexName");
        }

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config
        if (!is_dir($dirOrFilename)) {
            $io->error("$dirOrFilename does not exist.  set the directory in config or pass it as the first argument");
            return self::FAILURE;
        }


        // export?

        $io->success($this->getName() . ' success ' . $pixieCode);
        return self::SUCCESS;
    }

    private function configureIndex(Config $config, string $indexName): Indexes
    {

        $primaryKey = 'pixie_key';
        $index = $this->meiliService->getIndex($indexName, $primaryKey);

        foreach ($config->getTables() as $table) {
            foreach ($table->getProperties() as $property) {
                // the table pk is renamed to {tableName}_{pk}
                dd($property, $table, $config);
            }
        }

        $filterable = ['country_code', 'coreId', 'expected_language'];
        $sortable = ['country_code', 'coreId'];

        $results = $index->updateSettings($settings = [
            'displayedAttributes' => ['*'],
            'filterableAttributes' => $filterable, //  $this->datatableService->getFieldsWithAttribute($settings, 'browsable'),
            'sortableAttributes' => $sortable, // this->datatableService->getFieldsWithAttribute($settings, 'sortable'),
                "faceting" => [
        "sortFacetValuesBy" => ["*" => "count"],
        "maxValuesPerFacet" => $this->meiliService->getConfig()['maxValuesPerFacet']
    ],
            ]);

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