<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Survos\BootstrapBundle\Event\KnpMenuEvent;
use Survos\PixieBundle\CsvSchema\Parser;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Source;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Repository\CoreRepository;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Yaml\Yaml;
use Survos\WorkflowBundle\Service\WorkflowHelperService;


class PixieService
{
    const PIXIE_TRANSLATION = 'translation';
    // cache, indexed by filename
    private array $storageBoxes = [];

    public function __construct(
//        #[Autowire('%kernel.debug%')] private readonly bool                                        $isDebug,

        private EntityManagerInterface                           $pixieEntityManager,
        private SqliteService                                    $sqliteService,
        private readonly CoreRepository                          $coreRepository,
        private readonly bool                                    $isDebug = false,
        private array                                            $data = [],
        private readonly string                                  $extension = "pixie.db",
        private readonly string                                  $dbDir = 'pixie',
        private readonly string                                  $dataRoot = 'data', //
        private readonly string                                  $configDir = 'config/packages/pixie',
        private array                                            $bundleConfig = [],
        #[Autowire('%env(DATABASE_PIXIE_URL)%')] private ?string $pixieTemplateUrl = null,
        #[Autowire('%kernel.project_dir%')]
        private readonly ?string                                 $projectDir = null,
        private readonly ?LoggerInterface                        $logger = null,
        private readonly ?Stopwatch                              $stopwatch = null,
        private readonly ?PropertyAccessorInterface              $accessor = null,
        private readonly ?SerializerInterface                    $serializer = null,
        private readonly ?WorkflowHelperService                  $workflowHelperService = null,
        private ?Config                                          $config = null,
//        private ?DenormalizerInterface                      $denormalizer = null,
    )
    {
//        dd($this->isDebug);
//        dd($this->serializer->denormalize($this->data, Config::class));
//        assert($this->logger);
//        $this->denormalizer = $this->serializer; // ->denormalize($this->data, DenormalizerInterface::class);
    }


    /**
     * if null, use the value in survos_pixie.yaml, so dev can be less
     *
     * @param int|null $limit
     * @return int|null
     */
    public function getLimit(?int $limit = null): ?int
    {
        return is_null($limit) ? $this->bundleConfig['limit'] : $limit;

    }

    public function getPixieEntityManager(): EntityManagerInterface
    {
        return $this->pixieEntityManager;

    }

    public function getPixieFilename(string $pixieCode, ?string $filename = null, bool $autoCreateDir = false): string
    {
        if (!$filename) {
            $filename = $pixieCode;
        }
        $dir = $this->getPixieDbDir(); // no longer nested . "/$pixieCode";
        if ($autoCreateDir && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $filename = $dir . "/$filename.{$this->extension}";

        if (file_exists($filename)) {
            $filename = realpath($filename);
        }
        return $filename;

    }

    public static function getMeiliIndexName(string $pixieCode, ?string $subCode, ?string $tableName)
    {
        return 'px_' . $pixieCode . ($subCode ? ('_' . $subCode) : '') . '_' . $tableName;

    }

    private function resolveFilename($filename, ?string $type = null): ?string
    {

        if ($type && ($filename && !file_exists($filename))) {
            $root = match ($type) {
                'db' => $this->dbDir,
                'config' => $this->configDir,
                'data' => $this->dataRoot,
            };
            $filename = $root . "/$filename";
            if (!file_exists($filename)) {
                $filename = $this->projectDir . "/$filename";
            }
        }
        return ($filename && file_exists($filename)) ? $filename : null;
    }

    // fetch via an event, rather than injecting the service
    #[AsEventListener(event: StorageBoxEvent::class, priority: 50)]
    public function storageBoxListener(StorageBoxEvent $event): void
    {
        $mode = $event->getMode();
//        if ($event->isTranslation()) {
//            $mode = PixieInterface::PIXIE_TRANSLATION;
//        }
//        assert(!$event->isTranslation(), "use mode");
        $filename = $this->getPixieFilename($event->getPixieCode());
        if ($suffix = match ($mode) {
            'data' => null,
//            'translation' => PixieInterface::PIXIE_TRANSLATION_SUFFIX,
            'image' => PixieInterface::PIXIE_IMAGE_SUFFIX
        }) {
            $filename = str_replace('.pixie.db', '-' . $suffix . '.pixie.db', $filename);
        }

        $kv = $this->getStorageBox(
            $suffix ?? $event->getPixieCode(),
            filename: $suffix ? $filename : null,
        );
//        dd($kv);
        $event->setStorageBox($kv);
    }

    function getStorageBox(string  $pixieCode,
                           ?string $subCode = null,
                           ?string $filename = null, // since files can share a config?
                           bool    $destroy = false,
                           bool    $createFromConfig = false,
                           ?Config $config = null,
    ): StorageBox
    {
        // ideally we could drop this and get the configuration data without the file
        // this selects the proper database so when others access the em it is the right one
        assert(!str_contains($pixieCode, "/"), "pass in pixieCode, not filename");
//        assert($filename, $pixieCode . " $filename ");

        if (!$filename) {
            $filename = $this->getPixieFilename($pixieCode, $subCode);
        }
        if ($createFromConfig && !$config) {
            $config = $this->selectConfig($pixieCode);
        }
        // always parse config so we have it.  certainly could be optimized
//        if ($createFromConfig)
        {
            if (!$config) {
//                assert(false, "Pass in config for now.");
                // filename? Or code???  ugh,
                $config = $this->selectConfig($pixieCode);

            }
            // the array! someday the model.
//            $tables = $config->getTables();
//            foreach ($config->getTables() as $tableName => $table) {
//                dd($tableName, $table);
//            }
        }
        $destroy && $this->destroy($filename);
        if (!$kv = $this->storageBoxes[$filename] ?? false) {
            $class = $this->isDebug ? TraceableStorageBox::class : StorageBox::class;
            $kv = new $class($filename,
                $this->data, // for debug
                $config,
                pixieCode: $pixieCode,
                accessor: $this->accessor,
                logger: $this->logger,
                stopwatch: $this->stopwatch,
                templates: $this->getTemplates()
            );
            $this->storageBoxes[$filename] = $kv;
        }
//        dump($filename, storageBoxes: array_keys($this->storageBoxes));
        return $kv;
    }

//    function getStringBox(string $filename, array $tables=[]): StorageBox
//    {
//        return new StorageBox($filename, $tables, valueType: 'string', logger: $this->logger);
//    }

    function destroy(string $filename): void
    {
        $filename = $this->resolveFilename($filename);
        if ($filename && file_exists($filename)) {
            unlink($filename);
        }
    }

    /** @internal used in the DataCollector class */
    public function getData(): array
    {
        return $this->data;
        foreach ($this->storageBoxes as $filename => $storageBox) {
            $this->data[$filename] = $storageBox->getData();
        }
        return $this->data;
    }

    public function getConfig(string $pixieCode): Config
    {
        return $this->getConfigFiles()[$pixieCode];

    }

    /**
     * @return array<Config>
     */
    public function getConfigFiles(?string $q = null, ?string $pixieCode = null, int $limit = 0): array
    {

        $configs = [];
        foreach ($this->bundleConfig['pixies'] as $code => $pixie) {
            if ($q && !str_contains((string)$code, $q)) {
                continue;
            }
            if ($pixieCode && $code !== $pixieCode) {
                continue;
            }

//            https://www.strangebuzz.com/en/snippets/converting-an-array-into-an-object-with-the-symfony-serializer
//            $code=='auur' && dd($pixie['tables']['obj']);
            // insert the name so we don't have to fix it manually later
            foreach ($pixie['tables'] as $tableName => $tableData) {
                $pixie['tables'][$tableName]['name'] = $tableName;
            }
            $config = $this->serializer->denormalize($pixie, Config::class);
            $config->setPixieFilename($this->getPixieFilename($code));


            // eh.
            $resolvedDataPath = $this->resolveFilename($config->getSourceFilesDir(), 'data');
            $config->dataDir = $resolvedDataPath;

            $configs[$code] = $config;
        }
        return $configs;

        $finder = new Finder();
        $finder->depth("<1");
        $configs = [];
        $pattern = $pixieCode ?: ($q ?: '*');
        // this is only the configs in the configDir.
        foreach ($finder->files()->name("$pattern.yaml")
                     ->in($this->getConfigDir())->sortByName()->reverseSorting() as $file) {
            // we can optimize later...
            $code = $file->getFilenameWithoutExtension();
            $config = $this->selectConfig($code);
            assert($config, "invalid config $code");

            $resolvedDataPath = $this->resolveFilename($config->getSourceFilesDir(), 'data');
            $config->dataDir = $resolvedDataPath;
//            dd($config);
            // hacky!  configs can belong to more than one filename
            $config->setPixieFilename($this->getPixieFilename($code));
            assert($config->getPixieFilename(), "Missing pixie filename for $code");
            $configs[$code] = $config;
        }
        return $configs;
//        // we could parse these, though then we should cache them.  Since they're in config, we could cache them at compile-time
//        return glob($this->getConfigDir() . '/*.yaml');

    }

    public function getTemplates(): array
    {
        $templates = [];
        foreach ($this->bundleConfig['templates'] as $code => $template) {
            $templates[$code] = $this->serializer->denormalize($template, Table::class);
        }
        return $templates;

    }

    /**
     * @return array(<string, Property>)
     */
    public function getInternalProperties(): array
    {
        $templates = [];
        foreach ($this->bundleConfig['templates']['internal'] as $code => $property) {
            dd($code, $property);
        }
        return $templates;

    }

    public function importConfigToCore(Config $config): void
    {
        $coreCode = $config->getCode();
        if (!$this->coreRepository)
            // map config to core fields, then only use Core/Fields and not Table/Columns
            dd($config);


    }


    // @todo: add custom dataDir, etc.
    public function selectConfig(string $pixieCode, bool $switchDatabase = true): ?Config
    {
        static $configCache = null;

        $conn = $this->pixieEntityManager->getConnection();
        $currentName = pathinfo($this->dbName($pixieCode), PATHINFO_FILENAME);
        if ($currentName === $pixieCode) {
//            $switchDatabase = false;
        } else {
//            $conn->selectDatabase($this->dbName($pixieCode));
        }

        if ($switchDatabase) {
//            dd($currentName, $pixieCode);
            try {
                $this->pixieEntityManager->flush();
                $this->pixieEntityManager->clear();
            } catch (\Exception $e) {
                // warn?
            }
            $conn = $this->pixieEntityManager->getConnection();
            $toDbName = $this->dbName($pixieCode);
            $conn->selectDatabase($toDbName);
        }

        if (null === $configCache) {
            $configCache = $this->getConfigFiles();
        }
        try {
            // this is the owner in the sqlite file, not in the app.
            if ($config = $configCache[$pixieCode] ?? null) {
                $config = StorageBox::fix($config, $this->getTemplates());
            }
            if (!$config) {
                return null;
            }
            // not sure this should be here -- selectConfig is called during migrate, and owner doesn't yet exist
            $owner = $this->pixieEntityManager->getRepository(Owner::class)->find($pixieCode);
            $config->setOwner($owner);
        } catch (\Exception $e) {
//            assert(false);
            $this->logger->warning(" creating " . $pixieCode . "\n\n" . $e->getMessage());
//            return null;
        }
        return $config;

        dd($pixieCode, $this->bundleConfig);
        static $configs = [];
        if ($config = $configs[$pixieCode] ?? false) {
            return $config;
        }
        $configFilename = $this->getConfigFilename($pixieCode);
        assert($configFilename, "$configFilename $pixieCode");
        if (!file_exists($configFilename)) {
            return null;
        }
        assert(file_exists($configFilename), "$configFilename does not exist");
        try {
            $configData = Yaml::parseFile($configFilename, Yaml::PARSE_CONSTANT); // so we can use php constants!
            $yaml = Yaml::dump($configData);


//        $yaml = file_get_contents($configFilename);
//        $config = $this->denormalizer->denormalize($configData, Config::class);
//        dd(config: $config, data: $configData);
//        $config->setConfigFilename($configFilename);
            $config = $this->serializer->deserialize(
                $yaml,
                Config::class, 'yaml');
        } catch (NotNormalizableValueException $exception) {
            dd($configFilename, $exception->getMessage());
        }
        // nested array access: https://packagist.org/packages/dflydev/dot-access-data

        // if the properties are strings, we need to parse them
        foreach ($config->getTables() as $tableName => $table) {
            $properties = [];
            $table->setName($tableName);
            foreach ($table->getProperties() as $propIndex => $propData) {
                if (is_string($propData)) {
                    $property = Parser::parseConfigHeader($propData);
                } else {
                    $property = new Property(
                        index: $propData['index'] ?? null,
                        code: $propData['name'] ?? dd($propData),
                        generated: $propData['generated'] ?? true,
                        initial: $propData['initial'] ?? null,
                        type: $propData['type'] ?? null // maybe default type based on code?
                    );
                }
                // better would be to look for ## or something like that
                if ($propIndex == 0) {
                    $primaryKey = $property->getCode();
                    $table->setPkName($primaryKey);
                    $property->setIndex('PRIMARY');
                }
                $properties[] = $property;
            }
            $table->setProperties($properties);
        }

        $config->code = $pixieCode; // quirky
        $config->setConfigFilename($configFilename);
//        dd($config);
        assert($config instanceof Config);
//        assert($config->source, $configFilename . " missing source key");
//        assert($config->source instanceof Source);
        foreach ($config->getTables() as $idx => $table) {
            assert($table instanceof Table, "table $idx is not of class Table");
        }
//        dd($config, $configFilename, $config);
        $configs[$pixieCode] = $config;
//        dd($config);
        return $config;
    }

    public function getConfigFilename(string $pixieCode): string
    {
        // @todo: handle non-standard locations
        return $this->getConfigDir() . "/$pixieCode.yaml";
    }

    public function getConfigDir(bool $autoCreate = false): string
    {
        assert(!$autoCreate);
        $dir = $this->configDir;
        if (!file_exists($dir) && !str_starts_with($dir, "/")) {
            $dir = $this->projectDir . "/$dir";
        }

        if ($autoCreate && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;

    }

    public function getDataRoot(): string
    {
        return $this->addProjectDir($this->dataRoot);
    }

    public function getPixieDbDir()
    {
        return $this->addProjectDir($this->dbDir);
    }

    public function addProjectDir(string $s): string
    {
        if (!file_exists($s) && !str_starts_with($s, "/")) {
            $s = $this->projectDir . "/$s";
        }
        return $s;
    }

    public function removeProjectDir(string $s): string
    {
        return str_replace($this->projectDir . '/', '', $s);
    }


    public function getSourceFilesDir(?string $pixieCode = null,
                                      ?Config $config = null,
                                      bool    $autoCreate = false,
                                      bool    $throwErrorIfMissing = true,
                                      ?string $dir = null,
                                      ?string $subCode = null
    ): ?string
    {
        assert(!$autoCreate);
        if (!$config) {
            if (!$config = $this->selectConfig($pixieCode)) {
                return null;
            }

        }

        if (!$dir) {
            if (!$dir = $config->getSourceFilesDir()) {
                $dir = $this->dataRoot . "/$pixieCode";
            }
        }
        if ($subCode) {
            $dir .= "/$subCode";
        }

        $dir = $this->addProjectDir($dir);
        if ($autoCreate && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($throwErrorIfMissing) {
            assert(file_exists($dir), "Missing source files dir $dir");
        }
        return file_exists($dir) ? realpath($dir) : null;


    }

    #[AsMessageHandler]
    public function handleTransition(PixieTransitionMessage $message)
    {
        $flowName = $message->workflow;
        $transition = $message->transition;
        $kv = $this->getStorageBox($message->pixieCode);
        $row = $kv->get($message->key, $message->table);
        $workflow = $this->workflowHelperService->getWorkflow($row, $flowName);
        if (!$workflow->can($row, $transition)) {
            return; //
        }

        $tableName = $message->table;
        $key = $message->key;
        $workflow = $this->workflowHelperService->getWorkflow($row, $flowName);
        if ($workflow->can($row, $transition)) {
            $marking = $workflow->apply($row, $transition, [
                'kv' => $kv
            ]);
            $context = $marking->getContext();
            $markingString = array_key_first($marking->getPlaces());
            $data = $context['data'] ?? null;
            $mode = $context['mode'] ?? StorageBox::MODE_NOOP;
            $kv->beginTransaction();
            if ($mode !== StorageBox::MODE_NOOP) {
                if ($data) {
                    $data['marking'] = $markingString;
                    //                $row->setMarking($markingString);
                    //                dd($row);
                    //                dd($marking->getPlaces());
                    $x = $kv->set($data, $tableName, key: $key, mode: $mode);
//                    $current = $kv->get($key, $tableName);
                } else {
                    dd($context, $transition, $row);
                }
            } else {
                // update marking for no-op
                $kv->set([
                    'marking' => $markingString,
                ],
                    tableName: $message->table,
                    key: $key, mode: StorageBox::MODE_PATCH);
            }
            $this->logger->info("Transition $transition  $tableName.$key to $markingString");
            $kv->commit();

            // dispatch the FIRST valid next transition
            foreach ($context['nextTransitions'] ?? [] as $transition) {
                if ($workflow->can($row, $transition)) {
                    // apply it? Or dispatch it?  or recursively call this?
                    // since it's been saved (above), we will refetch it when this is recursively called
                    $this->handleTransition(new PixieTransitionMessage(
                        $message->pixieCode,
                        $message->key,
                        $message->table,
                        $transition,
                        $message->workflow
                    ));
//                    $marking = $workflow->apply($row, $transition, [
//                        'kv' => $kv
//                    ]);
//                    dd($marking, $row, $message, $transition);
                } else {
//                    dd($row, $transition, $context);
                }
            }

        } else {
            // no biggie, we can't transition, but the message itself doesn't fail.
            $marking = $row->getMarking();
            $this->logger->info("cannot transition from $marking to $transition");
        }

    }

    public function getCountsByCore(): array
    {
        $countsByCore = $this->pixieEntityManager->getRepository(Row::class)->createQueryBuilder('s')
            ->join('s.core', 'core')
            ->groupBy('core.code')
            ->select(["core.id as coreCode, count(s) as count"])
            ->getQuery()
            ->getArrayResult();
//        dd($countsByCore);
        foreach ($countsByCore as $x) {
            $data[$x['coreCode']] = $x;
        }
        return $data;
    }

    public function populateRecordWithRelations(Row $item, Config $config): Row
    {
        return $item;
        $table = $config->getTables()[$item->getTableName()];
        $properties = $table->getProperties();
        $data = (array)$item->getData();
        foreach ($properties as $property) {
            $propertyName = $property->getCode();
            if (!$relatedTable = $property->getSubType()) {
                continue;
            }
            {
                // get the related item from the PK stored in the item, replace it with the actual record, but without the _id?
                if (!$relatedId = $data[$propertyName] ?? null) {
                    continue;
                }
                // json from sqlite is stored as a string.
                if (is_string($relatedId) && json_validate($relatedId)) {
                    $relatedId = json_decode($relatedId);
                }
                // the subtype table needs to exist!
                if ($config->getTable($property->getSubType())) {
                    // publish these properties as flickr tags, including label and description
//                    if ($property->getCode() == 'classification') {
//                        dd($relatedId, $propertyName, $relatedName, $property);
//                    }
                    if ($relatedId) {
                        // @todo: get many-to-many right, e.g. walters coll
                        if (is_array($relatedId)) {
                            $data[$propertyName] = [];
                            foreach ($relatedId as $code) {
                                // during dev, relations may not be loaded, walters has 4000 creators
                                if ($relatedItem = $kv->get($code, $property->getSubType())) {
//                                    dd($data, $propertyName, $relatedItem);
                                    $data[$propertyName][] = $relatedItem;
//                                        $data[$propertyName][] = $relatedItem->label();
                                } // subtype is related table
                            }
                        } else {
                            assert(!is_iterable($relatedId), json_encode($relatedId));
                            $relatedItem = $kv->get($relatedId, $property->getSubType()); // subtype is related table
                            $relatedName = str_replace('_id', '', $propertyName);
                            // @todo: list or 1-many
                            $data[$relatedName] = $relatedItem;
//                            if ($propertyName == 'classification') dd($data, $relatedName);
                        }
                    }
                }
            }
        }
        // need to be selective about when we save this.
        $item->setData($data);
        return $item;
    }

    public function dbName(string $code, bool $throwErrorIfMissing = false): string
    {
        //dd($this->pixieTemplateUrl);
        $params = $this->pixieEntityManager->getConnection()->getParams();
        //$dbName = str_replace('pixie_template', $code, $params['path']);
        $dbName = str_replace(pathinfo($params['path'], PATHINFO_FILENAME), $code, $params['path']);
        if ($throwErrorIfMissing) {
            assert(file_exists($dbName), $dbName);
        }
        return $dbName;

    }

    public function migrateDatabase(
        Config $config)
    {
        // get the template first (./c d:sch:update --force --em=pixie)
        $pixieConn = $this->pixieEntityManager->getConnection();
        $databasePlatform = $pixieConn->getDatabasePlatform();
        $templateName = $this->dbName('pixie_template', true);
//        $conn->selectDatabase($templateName);
        try {
            $fromSchemaManager = $pixieConn->createSchemaManager();
            $fromSchema = $fromSchemaManager->introspectSchema();
        } catch (\Exception $e) {
            dd($e->getMessage(), $config->getCode());
        }

        //        $conn->selectDatabase($dbName);
        $toDbName = $this->dbName($config->getCode(), false);
//        dd($toDbName, $templateName);
        $schemaTool = new SchemaTool($this->pixieEntityManager);
        // now prep for the new database

        // from doctrine:schema:update
//        $classes = $this->pixieEntityManager->getMetadataFactory()->getAllMetadata();
//        $toSchema   = $schemaTool->getSchemaFromMetadata($classes);
//        $sqls = $schemaTool->getUpdateSchemaSql($classes);
//        dd($sqls);
//        $fromSchema = $schemaTool->createSchemaForComparison($toSchema);
//        return $this->platform->getAlterSchemaSQL($schemaDiff);
        $comparator = $fromSchemaManager->createComparator();

        $this->selectConfig($config->getCode());
        $toConnection = $this->pixieEntityManager->getConnection();
//        $toConnection = DriverManager::getConnection(['path' => $toDbName, 'driver' => 'pdo_sqlite']);
//        $toConnection->selectDatabase($toDbName);
        // https://til.simonwillison.net/sqlite/enabling-wal-mode
        // https://www.powersync.com/blog/sqlite-optimizations-for-ultra-high-performance
        foreach ([
                     'pragma journal_mode = WAL',
                     'pragma synchronous = normal',
                     'pragma journal_size_limit = 6144000'
                 ] as $pragma) {
            $toConnection->executeQuery($pragma);

            // @todo: PRAGMA journal_mode=delete; to turn off, maybe before export or something
        }

        $toSchemaMananger = $toConnection->createSchemaManager();
        $toSchema = $toSchemaMananger->introspectSchema();
//        $fromSchemaManager = $fromConnection->createSchemaManager();
//        $fromSchema = $fromSchemaManager->introspectSchema();

        assert($toSchema !== $fromSchema);
        $schemaDiff = $comparator->compareSchemas($toSchema, $fromSchema);
        $queries = $databasePlatform->getAlterSchemaSQL($schemaDiff);
        foreach ($queries as $query) {
            try {
//            dump(diffSql: $diffSql, q: $queries);
                $toConnection->executeQuery($query);
            } catch (\Exception $exception) {
                dd($exception->getMessage());
                // it already exists.
            }
        }

        // templates?
        $config = StorageBox::fix($config);
        $views = [];
        $actualFields = ['label', 'code', 'id'];
        foreach ($tables = $config->getTables() as $table) {
            $fieldNames = array_map(fn(Property $property) => $property->getCode(),
                iterator_to_array($table->getProperties()));

            $fields = array_map(fn(Property $property) => in_array($property->getCode(), $actualFields)
                ? "row." . $property->getCode()
                : sprintf("json_extract(data, '$.%s') as %s",
                    $property->getCode(),
                    $property->getCode()
                ),
                iterator_to_array($table->getProperties()));
//            foreach ($actualFields as $actualField) {
//                $fieldNames[] = $actualField;
//                $fields[] = $actualField;
//            }

            $view = 'v_' . $table->getName();
            $views[] = "DROP view if exists $view";
//             $x = "select json_extract(_data, '\$.$label') as label from row";
            $views[] = sprintf("CREATE VIEW $view (%s) AS
                SELECT %s
                 from row where core_id = '%s'",
                implode(', ', $fieldNames),
                implode(', ', $fields),
                $table->getName());
//                 from row inner join core on (row.core_id = core.id) where core.id = '%s'",

            foreach ($table->getProperties() as $property) {
            }
        }
        foreach ($views as $view) {
            try {
                $toConnection->executeQuery($view);
            } catch (\Exception $exception) {
                dd($exception->getMessage(), $view);
            }
        }
        return $toConnection;
        $tables = [];
        foreach ($fromSchema->getTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $tables[$table->getName()]['columns'][] = $column->getName();
            }
        }
        // @todo: use our Model tables
//        try {
//        } catch (\Exception $exception) {
//            dd($exception, $sourceReferences);
//        }


//        $queries = $schemaDiff->toSql($databasePlatform); // queries to get from one to another schema.

        // now do a diff so we can keep the dbs in sync
//            $diffSql = join(';', $queries);
//            dump(diffSql: $diffSql);
//            $conn->executeQuery($diffSql);
//        try {
//        } catch (\Exception $exception) {
//            // it already exists.
//        }
        $sc = $conn->createSchemaManager();
//        dd($sc->listTables(), $queries);


        $schema = new \Doctrine\DBAL\Schema\Schema();
        $myTable = $schema->createTable("my_table");
        $myTable->addColumn("id", "integer", ["unsigned" => true]);
        $myTable->addColumn("username", "string", ["length" => 32]);
        $myTable->addColumn("age", "integer");
        $myTable->setPrimaryKey(["id"]);
        $myTable->addUniqueIndex(["username"]);
        $myTable->setComment('Some comment');

        $myForeign = $schema->createTable("my_foreign");
        $myForeign->addColumn("id", "integer");
        $myForeign->addColumn("user_id", "integer");
//        $myForeign->addForeignKeyConstraint($myTable, ["user_id"], ["id"], ["onUpdate" => "CASCADE"]);


        $queries = $schema->toSql($conn->getDatabasePlatform()); // get queries to create this schema.

        $schemaManager = $conn->createSchemaManager();
        $comparator = $schemaManager->createComparator();
        $schemaDiff = $comparator->compareSchemas($fromSchema, $schema);

        $databasePlatform = $conn->getDatabasePlatform();
        $diffs = $databasePlatform->getAlterSchemaSQL($schemaDiff);

        $sc->introspectSchema();
        $newSchema = $fromSchemaManager->introspectSchema();
        foreach ($newSchema->getTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $tables[$table->getName()]['columns'][] = $column->getName();
            }
        }


        return [$tables, $diffs];

//        foreach ($schemaDiff->toSql($databasePlatform) as $sql) {
//            dump($sql);
//            $conn->executeQuery($sql);
//        }
//        try {
//        } catch (\Exception $exception) {
//            // it already exists.
//        }
//        dd($fromSchema);

    }

}
