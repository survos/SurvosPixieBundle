<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Psr\Log\LoggerInterface;
use Survos\BootstrapBundle\Event\KnpMenuEvent;
use Survos\PixieBundle\CsvSchema\Parser;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Source;
use Survos\PixieBundle\Model\Table;
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
    const PIXIE_TRANSLATION='translation';
    // cache, indexed by filename
    private array $storageBoxes = [];

    public function __construct(
        private bool                                        $isDebug, private readonly EventDispatcherInterface $eventDispatcher,
        private array                                       $data = [],
        private string                                      $extension = "pixie.db",
        private string                                      $dbDir = 'pixie',
        private string                                      $dataRoot = 'data', //
        private string                                      $configDir = 'config/packages/pixie',
        private array                                       $bundleConfig = [],
        #[Autowire('%kernel.project_dir%')] private ?string $projectDir = null,
        private ?LoggerInterface                            $logger = null,
        private ?Stopwatch                                  $stopwatch = null,
        private ?PropertyAccessorInterface                  $accessor = null,
        private ?SerializerInterface                        $serializer = null,
        private ?WorkflowHelperService                      $workflowHelperService = null,
        private ?DenormalizerInterface                      $denormalizer = null,
    )
    {
//        dd($this->serializer->denormalize($this->data, Config::class));
//        assert($this->logger);
        $this->denormalizer = $this->serializer; // ->denormalize($this->data, DenormalizerInterface::class);
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

    public function getPixieFilename(string $pixieCode, ?string $filename = null, bool $autoCreateDir = false): string
    {
        if (!$filename) {
            $filename = $pixieCode;
        }
        $dir = $this->getPixieDbDir() . "/$pixieCode";
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

    private function resolveFilename($filename, string $type = null): ?string
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
        if ($event->isTranslation()) {
            $filename = $this->getPixieFilename($event->getPixieCode());

            $translationPixieFilename = str_replace('.pixie.db', '-translation.pixie.db', $filename);
            $kv = $this->getStorageBox(
                self::PIXIE_TRANSLATION,
                filename: $translationPixieFilename,
            );
        } else {
            $kv = $this->getStorageBox(
                $event->getPixieCode(),
            );

        }
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
        assert(!str_contains($pixieCode, "/"), "pass in pixieCode, not filename");
//        assert($filename, $pixieCode . " $filename ");

        if (!$filename) {
            $filename = $this->getPixieFilename($pixieCode, $subCode);
        }
        if ($createFromConfig && !$config) {
            $config = $this->getConfig($pixieCode);
        }
        // always parse config so we have it.  certainly could be optimized
//        if ($createFromConfig)
        {
            if (!$config) {
//                assert(false, "Pass in config for now.");
                // filename? Or code???  ugh,
                $config = $this->getConfig($pixieCode);

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

    /**
     * @return array<Config>
     */
    public function getConfigFiles(string $q = null, ?string $pixieCode = null, int $limit = 0): array
    {

        $configs = [];
        foreach ($this->bundleConfig['pixies'] as $code => $pixie) {
            if ($q && !str_contains($code, $q)) {
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
            $config = $this->getConfig($code);
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

    // @todo: add custom dataDir, etc.
    public function getConfig(string $pixieCode): ?Config
    {
        assert($pixieCode);
        static $configCache = null;
        if (null === $configCache) {
            $configCache = $this->getConfigFiles();
        }

//        if ($extends = $table->getExtends()) {
//            // deserialize
//        }


        $config = StorageBox::fix($configCache[$pixieCode], $this->getTemplates());
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
                        generated: isset($propData['generated']) ? $propData['generated'] : true,
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
                                      string  $dir = null,
                                      string  $subCode = null
    ): ?string
    {
        assert(!$autoCreate);
        if (!$config) {
            $config = $this->getConfig($pixieCode);
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

    public function populateRecordWithRelations(Item $item, Config $config, StorageBox $kv): Item
    {
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
                if (!$relatedId = $data[$propertyName]??null) {
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


}
