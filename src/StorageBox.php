<?php

namespace Survos\PixieBundle;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

// consider: https://github.com/morris/dop for binding arrays as parameters and sub-queries
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use JetBrains\PhpStorm\Deprecated;
use \PDO;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\CsvSchema\Parser;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Index;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieTranslationService;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use function PHPUnit\Framework\directoryExists;
use function Symfony\Component\String\u;

/*
 *
 * https://gist.github.com/sbrl/c3bfbbbb3d1419332e9ece1bac8bb71c
 *
 * a multi-table, multi-lingual Sqlite key-value data store.
 * @license Apache 2.0
 */

#[Deprecated]
class StorageBox
{
    const MODE_REPLACE = 'replace';
    const MODE_NOOP = 'noop';
    const MODE_PATCH = 'patch';
    /**
     * The SQLite database connection.
     * @var \PDO
     */
    private \PDO $db;
    private array $tables = []; // from the config
    private array $schemaTables = []; // from the schema inspection
    private bool $inTransaction = false;
    private ?array $regexRules = [];
    private $keyCache = []; // by table, for has()

    /**
     * Initialises a new store connection.
     * @param string $filename The filename that the store is located in.
     * @param array $tablesToCreate The ADDITIONAL tables to create with writing.  Others may already exist.
     */
    function __construct(private readonly string                              $filename,
                                     array                                       &$data, // debug data, passed from Entity
                                     private ?Config                             $config = null, // for creation only.  Shouldn't be in constructor!
//                                     private array                     $tablesToCreate = [],
//                                     private ?array                     $regexRules = [],
                                     private ?string                             $currentTable = null,
                                     private readonly ?int                                $version = 1,
                                     private readonly string                              $valueType = 'json', // eventually jsonb
                                     private readonly bool                                $temporary = false, // nyi
                                     private readonly ?LoggerInterface           $logger = null,
                                     private readonly ?PropertyAccessorInterface $accessor = null,
                                     private readonly ?SerializerInterface $serializer=null,
                                     private array                               $formatters = [],
                                     private readonly ?Stopwatch                 $stopwatch = null,
                                     private readonly ?string                    $pixieCode = null, //
                                     private readonly array $templates=[],
    )
    {
        $path = $this->filename;

        // PDO creates the db if it doesn't exist, so check after
        if (0) // skip creating, everything should be in pixieEntityManager now
        if (!file_exists($path)) {
            $dir = pathinfo($path, PATHINFO_DIRNAME);
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            try {
                $this->db = new \PDO("sqlite:" . $path);
            } catch (\PDOException $e) {
                throw new \LogicException("Invalid database connection: " . $path . "\n\n" . $e->getMessage());
//                dd($path, $e->getMessage());
//                return;
            }
            // this could speed things up a lot!
            $this->db->query("PRAGMA journal_mode=WAL");
            $this->db->query("PRAGMA lock_timeout=5");
            $this->db->setAttribute(PDO::ATTR_TIMEOUT, 10);
//            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            try {
                $this->db = new \PDO("sqlite:" . $path);
            } catch (\PDOException $e) {
                dd($path, $e->getMessage());
                return;

            }
        }

        if ($this->config) {
            $this->config = self::fix($this->config, $this->templates);
        }
        // fix until we fix in the definition parser
        if ($this->config) {
            $this->createTables($this->config);
        }
    }

    /**
     * @param Config $config
     * @param array<string, Table> $templates
     * @return Config
     */
    static public function fix(Config $config,array $templates=[]): Config
    {
        // if the property code is used, get the definition from here, rather than repeating it.  'extends' will add the property codes, this only add them if they key exist.  add a use: key with order?
        $internalProperties = [];
        if ($internalTemplate = $templates['internal']??null) {
            foreach ($internalTemplate->getProperties() as $property) {
                if (is_string($property)) {
                    $property = Parser::parseConfigHeader($property);
                }
                $internalProperties[$property->getCode()] = $property;
            }
        }

        // we are over-calling fix!
        static $fixed=[];
        if (in_array($config->code, $fixed)) {
//            return $config;
        }
        $fixed[] = $config->code;
        foreach ($config->getTables() as $tableName => $table) {
            $newProperties = [];
            foreach ($table->getUses() as $internalCode) {
                $newProperties[] = $internalProperties[$internalCode];
            }

//            $tableName=='obj' && dd($internalProperties, table: $table, uses: $table->getUses(), extends: $table->getExtends());
//            $tableName=='obj' && dd($config, $table, $tableName, $newProperties);
            if (false) // this handles extends, which we dont use with the doctrine schema
            if ($extends = $table->getExtends()) {
                SurvosUtils::assertKeyExists($extends, $templates);
                /** @var Table $templateTable */
                $templateTable = $templates[$extends];
                if ($templateTable->getWorkflow()) {
                    $table->setWorkflow($templateTable->getWorkflow());
                }
                foreach ($templates[$extends]->getProperties() as $propIndex => $property) {
                    // better probably to push the properties to table rather than repeating this code.
                    if (is_string($property)) {
                        $property = Parser::parseConfigHeader($property);
                        $newProperties[] = $property;
                    }
                    if ($propIndex == 0) {
                        $primaryKey = $property->getCode();
                        $table->setPkName($primaryKey);
                        $property->generated = false;
                        $property->setIndex('PRIMARY');
//                        $tableName=='image' && dump($propIndex, $property);
//                        $tableName=='image' && dd($property);
                    }
                }
            }
            // now the pixie-specific properties
            foreach ($table->getProperties() as $propIndex => $property) {
                if (is_string($property)) {
                    $property = Parser::parseConfigHeader($property);
                    $newProperties[] = $property;
                } else {
                    $newProperties[] = $property;
                }
//                $tableName=='image' && dd($table);
                if ( (!$table->getPkName()) && $propIndex == 0) {
                    $primaryKey = $property->getCode();
                    $table->setPkName($primaryKey);
                    $property->setIndex('PRIMARY');
                }

            }

//            $tableX = $config->tables[$tableName];
            $table->setProperties($newProperties);
            $table->setName($tableName);
            assert($table->getPkName(), $tableName . " is missing a pkName " . $config->code);
//            $tableName=='image' && dump(__LINE__, __METHOD__, $newProperties, $table->getPkName());
//            dd($tableX, $table);
            assert(count($newProperties), "no new properties $tableName");
            assert(count($table->getProperties()), $tableName);
            $config->tables[$tableName] = $table;
        }
        return $config;


    }

    public function getConfig(): ?Config
    {
        if (!$this->config) {
            assert(false, "missing config");
        }
        return $this->config;
    }

    public function getPixieCode(): ?string
    {
        return $this->pixieCode;
    }

    public function createTables(Config $config): void
    {
//        if ($this->db->inTransaction()) {
//            $this->db->commit();
//        }
//            $sth = $this->db->query($sql = "SELECT name FROM sqlite_master where type='table'");
//        try {
//        } catch (\Exception $e) {
//            throw new \Exception("Unable to create/use : " . $config->code . "\n" . $e->getMessage());
//        }
//        $this->schemaTables = $sth->fetchAll(PDO::FETCH_COLUMN); // load the existing tables
//        $this->beginTransaction();
        if ($hasTranslations = true) {
            $table = (new Table(
                name: ($tableName = PixieInterface::PIXIE_STRING_TABLE) ,
                pkName: 'hash',
                properties: $properties = [
                    new Property('hash', 'text', index: 'PRIMARY', generated: false),
                    new Property('locale', 'text', index: 'INDEX'),
                    new Property('text', 'text'),
                    new Property(PixieInterface::PIXIE_STRING_TRANSLATION_KEY, 'text'),
                ]));
                $config->addTable($tableName, $table);
                $this->tables[$tableName] = $table;

//            $this->createTable($tableName, $table, $this->valueType);
//            $this->schemaTables[] = $tableName;
//            $this->commit();
//            dd($tableName);
//            $config->addTable($table->getName(), $table);

        }

        foreach ($config->getTables() as $tableName => $table) {
            assert($table instanceof Table, json_encode($table));
            if (str_starts_with($tableName, "@")) {
                continue; // @list is the template name, not a real table
            }

            if (!in_array($tableName, $this->schemaTables)) {
                $this->createTable($tableName, $table, $this->valueType);
                $this->tables[] = $table;
            }


//            if (!in_array($tableName, $this->schemaTables)) {
//                $_tableName = '_tables'; // for tracking table counts and schema
//                $table = (new Table(
//                    name: $_tableName,
//                    pkName: 'id',
//                    properties: [
//                    new Property('id', 'text', generated: false), // the tableName
//                    new Property('count', 'int')
//                ]));
//                $this->createTable($tableName, $table, $this->valueType);
//                $config->addTable($_tableName, $table);
//                $this->tables[$_tableName] = $table;
//            }

        }
//        $this->commit();

        // auto-create, or at least validate
        if (0)
        foreach ($config->getTables() as $tableName => $table) {
            assert($table instanceof Table, $tableName);
            foreach ($table->getProperties() as $property) {
                assert($property instanceof Property, json_encode($property));

                if ($list = $property->getListTableName()) {
//                    assert($this->hasTable($list), "until auto-create works, create a table for each list, $list");
//                $listTable = $this->getTable($tableName);
                }
            }
        }

    }

    public function addFormatter(callable $callable)
    {
        $this->formatters[] = $callable;
    }

    // @todo: make this an event listener?
    // given the HEADER array, map the key names, for array_combine or csv getRecords
    static public function mapHeader(array $header, string $propertyRule, ?string $tableName = null, array $regexRules = []): array
    {
        // $fieldName is the attribute (json) or column name (csv)
        $newHeaders = [];
//        $regexRules = $this->regexRules[$tableName] ?? [];
//        dd(tableName: $tableName, regex: $regexRules);
        foreach ($header as $idx => $fieldName) {
            $newFieldName = trim((string) $fieldName);
            if (empty($newFieldName)) {
                $newFieldName = "col-$idx";
            }
            foreach ($regexRules as $regex => $value) {
//                assert($regex, $regex);
//                dump($regex, $this->regexRules);
//                assert(preg_match($regex, ''), $regex);
                if (preg_match($regex, (string) $fieldName, $mm)) {
                    $newFieldName = $value; // @todo: apply a function or rule
                    if (in_array($newFieldName, $newHeaders)) {
                        throw new \Exception("$newFieldName already matched! $fieldName\n" .
                            implode("\n", array_filter($newHeaders, fn($header) => preg_match($regex, (string) $header)))
                        );
                    }
                    break; // match first rule only
                }
            }

            $lastChar = $newFieldName[-1];
            if (in_array($lastChar, [',', '|', ';'])) {
                // ugh, terrible side effect!
//                $this->explodeFields[$newFieldName] = $lastChar;
                $newFieldName = str_replace($lastChar, '', $newFieldName);
//                dd($newFieldName);
            }
//            if (preg_match($regex, $fieldName, $mm)) {

                $newFieldName = match ($propertyRule) {
                    'preserve' => $newFieldName,
                    'snake' => u($newFieldName)->snake()->toString(),
                    'camel' => u($newFieldName)->camel()->toString()
                };
                assert(!str_contains(' ', (string) $newFieldName), "invalid $propertyRule property code: [$newFieldName]");
                $newHeaders[] = $newFieldName;
//            }
        }
        $result =  array_combine($newHeaders, $header);
        return $result;
    }

    public function getColumnMap()
    {
        // ugh, complicated


    }

    public function select(string $tableName): self
    {
//        $tables = $this->getTableNames(); // not cached!
//        assert(count($tables), "no tables in $this->filename");
//        if (!in_array($tableName, $tables)) {
//            throw new \LogicException("$tableName $this->filename is not in tables:\n\n" . implode("\n", $tables));
//        };
        $this->currentTable = $tableName;
        return $this;

    }

    public function getVersion(): string
    {
        return "1.0";
    }

    /**
     * @param array $regexRules sequence of rules to rename columns
     * @param array $tables select which columns to apply this to.
     * @return void
     */
    public function map(array $tableRegexRules, array $tables = ['__all'])
    {
        // __all is special key for all tables
        foreach ($tables as $table) {
            $this->regexRules[$table] = $tableRegexRules;
        }
    }

    public function getPrimaryKey(string $tableName): ?string
    {
        $tableName ??= $this->currentTable;
        assert($tableName);
        // https://stackoverflow.com/questions/10472103/sqlite-query-to-find-primary-keys
        // use <> 0 if multiple
        $result = $this->query($sql = "SELECT l.name FROM pragma_table_info('$tableName') 
    as l WHERE l.pk = 1");
        $pk = $result->fetchColumn();
        if (!$pk) {
            $this->logger->warning("Missing $tableName");
        }
//        assert($pk, $tableName . $sql);
//dd($result->fetchColumn(), $result);
        return $pk;
//dd($result->fetchColumn(), $result->fetchAll(), $tableName, $sql);
//
//        $schemaTables = $this->inspectSchema();
//        $table = $schemaTables[$tableName] ?? null;
//
//        return $table?->getPkName();

    }


    /* get the DBAL schema.
     *
     */
    public function inspectSchema(?string $filename = null): array
    {
        $filename ??= $this->getFilename();

        static $tables = [];
        if (array_key_exists($filename, $tables) && count($tables[$filename])) {
            return $tables[$filename];
        }

        // ripe for caching, refactoring, etc.
        // is it necessary to open it again, or can we get this from the PDO?
        $connectionParams = [
            'path' => $filename,
            'driver' => 'pdo_sqlite',
        ];
        $conn = DriverManager::getConnection($connectionParams);
//            dump($connectionParams, $filename);
        $sm = $conn->createSchemaManager();
        $fromSchema = $sm->introspectSchema();

        $tables[$filename] = [];

        foreach ($fromSchema->getTables() as $schemaTable) {
            $primaryIndex = $schemaTable->getIndex('primary');
            //
            $table = new Table($schemaTable->getName(),
                $schemaTable->getColumns(),
                pkName: implode('-', $primaryIndex->getColumns()));
//                foreach ($schemaTable->getColumns() as $column) {
//                    $tables[$schemaTable->getName()]['columns'][] = $column->getName();
//                }
            $tables[$filename][$schemaTable->getName()] = $table;
        }
//            dd($fromSchema, $tables);
        try {
        } catch (\Exception $exception) {
            dd($exception, $filename);
        }
        return $tables[$filename];
    }


    /**
     * @param string|array $indexConfig
     * @return Index[] array
     */
    static function getIndexDefinitions(null|string|array $indexConfig): array
    {

        $indexes = [];
        if (empty($indexConfig)) {
            return $indexes;
        }
        if (is_string($indexConfig)) {
            $indexConfig = array_map(fn($key) => $key ? trim($key) : assert($key, "empty key! " . $indexConfig),
                explode(',', $indexConfig));
        }

        $primaryIndex = 0; // by default
        foreach ($indexConfig as $indexId => $indexName) {
            if (is_array($indexName)) {
                dump($indexConfig, $indexName, $indexId);
                assert(false);
                // parse type and maybe regex rule
                continue;
            }

            // pk is first unique key (or first key).  option to use rowid?
            if (!$primaryIndex && str_starts_with((string) $indexName, '&')) {
                $primaryIndex = $indexId;
            }
            if (str_contains((string) $indexName, '|')) {
                [$indexName, $type] = explode('|', (string) $indexName);
            } else {
                $type = 'TEXT';
            }

            // break the string up into the index model
            $index = new Index($indexName, $type, isUnique: str_starts_with((string) $indexName, '&'));
            $indexes[] = $index;
        }

        // set primary and unique
        $indexes[$primaryIndex]->isPrimary = true;

        return $indexes;

    }

    /**
     * @param string $tableName
     * @param string|array Index[] $indexes
     * @param string $valueType
     * @return void
     */
    public function createTable(string $tableName,
                                Table  $table,
                                string $valueType = 'JSON',
//        array $columns=[]
    ): void
    {
        $columns = [];
        // by this point, the table properties are already objects
        // really it's propertyDataArray

        // more json examples at https://www.sqlitetutorial.net/sqlite-json/
        $indexSql = [];
        // @todo: improve PK: https://www.sqlitetutorial.net/sqlite-primary-key/
        // a generated column can't be the primary key, but interesting: https://sqlite.org/forum/info/5928225848d0409f

        $propertyIndexes = [];
        $properties = [];
//        $tableName=='image' && dump(count($table->getProperties()));
        foreach ($table->getProperties() as $property) {
//            $tableName=='str' && dump($property);

//            assert(false);
//            dd($property, $tableName);
            $propertyCode = $property->getCode();
            assert($property::class === Property::class);
            if ($list = $property->getListTableName()) {
                // create the relation.  At the moment, all list ids are ints
                $foreignKeys[$propertyCode] = $list;
//                $columns[$propertyCode] = "{$propertyCode}_id INT REFERENCES [$list]([id])";
//                continue;
                $property->generated = true; // false; // e.g. translations, ['en','es']
//                CREATE TABLE [legislator_terms] ([legislator_id] TEXT REFERENCES [legislators]([id]),
//                assert($this->hasTable($list), "until auto-create works, create a table for each list, $list");
//                $listTable = $this->getTable($tableName);
            }
            // hack, property->setIndex would be better.
            if ($property->getIndex()) {
                if (array_key_exists($propertyCode, $propertyIndexes)) {
                    continue;
                }
                assert(!array_key_exists($propertyCode, $propertyIndexes), "$propertyCode already exists.");

                $propertyIndexes[$propertyCode] = new Index($property->getCode(),
                    isPrimary: $property->getIndex() === 'PRIMARY');
//                $tableName=='image' && ($property->getCode()==='key') && dump(keyIndex: $propertyIndexes[$property->getCode()]);
            }
            if (in_array($propertyCode, $table->getTranslatable())) {
                $property->generated = true;
                $property->translatable = true;
            }
            $properties[$property->getCode()] = $property; // now with the index
//            $properties[] = $property; // now with the index
        }
//        $table->isObj() && dd($properties);
        if ($translatableFields = $table->getTranslatable()) {

            foreach ($translatableFields as $translatableProperty) {
                $properties[$translatableProperty] = new Property(
                    $translatableProperty,
                    translatable: true,
                    type: 'text' // xxh3 and is related to _translation
                );
            }
            // we can automatically encode/decode until we have a real json type
//            $properties['_t'] = new Property(
//                '_t',
//                type: 'json'
//            );
        }
//        dd($tableName, $indexes);
        /**
         * @var int $indexId
         * @var Index $index
         */

        if (count($table->getTranslatable())) {
            $properties[PixieInterface::TRANSLATED_STRINGS] = new Property(
                PixieInterface::TRANSLATED_STRINGS,
                'text',
                generated: true, // for now!
            );
        }


        foreach ($properties as $name => $property) {
//            $name = $property->getCode();
            $index = $propertyIndexes[$name] ?? null;
            // @todo: handle auto-increment
            if ($index) {
                $type = $property->getType();
//                $tableName=='image' && dd($type, $property, $propertyIndexes);
                if ($index->isPrimary) {
                    $primaryKey = "$name $type PRIMARY KEY";
                    $columns[$name] = $primaryKey;
                    continue; // we don't need an explicit index for the PK
                }
            }

            // https://dbschema.com/2023/06/04/sqlite/constraints/#constraint

            // also see json_each example at https://sqlime.org/#deta:m97q76wmvzvd
            // create a generated column so that it all happens internally
            // json_extract('{"a":"xyz"}', '$.a') → 'xyz'
//                https://www.sqlite.org/gencol.html

            // d INT GENERATED ALWAYS AS (a*abs(b)) VIRTUAL,
            $type = ($property->getType() ?? 'TEXT');
            if ($property->getListTableName()) {
                $type = 'text'; // xxh3 of the label, or given. not auto-inc
            }

            if ($type === 'tr_text') {
                $columns[$name] = "$name TEXT "; /* hash */
            } else {
                $columns[$name] = "$name " . 'text'; // ($type=='json' ? 'text' : $type);
            }
            if ($default = $property->getInitial()) {
                $columns[$name] .= sprintf(' DEFAULT %s ', is_string($default) ? sprintf('"%s"', $default) : $default);
            }
            if ($property->getType() == 'json') {
                // maybe someday we can split it out, but for now keep it as is
                $property->generated = true; // e.g. translations, ['en','es']
            }
            if ($property->translatable) {
                $columns[$name] .=
                    " GENERATED ALWAYS AS (json_extract(_raw, '\$.$name')) /* xxh3 code, actual string is at _t[$name] */ ";
            } else {
                if ($property->generated) {
                    $columns[$name] .=
                        " GENERATED ALWAYS AS (json_extract(_raw, '\$.$name'))  ";
                } elseif ($relatedTable = $foreignKeys[$property->getCode()] ?? null) {
                    $columns[$name] .= "  REFERENCES [$relatedTable]([id]) "
                        . " GENERATED ALWAYS AS (json_extract(_raw, '\$.$name')) STORED /*?*/";
//                dd($columns);
                }
            }
            if ($index) {
                $indexSql[$tableName . $name] = "create index {$tableName}_{$name} on $tableName($name) /* @filterable */";
            }
        }
//        $columns['_t'] = '_t TEXT /* keyed by locale, then fieldname */';
        // e.g. _t.en.label='box', or _t = {en: {label: box, desc: "box description"}, "es": {label:...
//        foreach ($translationRows as $translation) {
//            $translations[$translation->target()][$translatableField] = $translation->text();
//        }

//        $columns['_att'] = '_att TEXT'; // type=att
//        $columns['json'] = '_extra TEXT'; // original data minus defined properties
        $columns['raw'] = '_raw TEXT'; // original data sent to ->set()

//        dd($tableConfig);
//        array_unshift($columns, $primaryKey);

        $sql = sprintf("CREATE TABLE IF NOT EXISTS %s (\n%s\n) strict; \n\n%s", $tableName,
            implode(",\n    ", array_values($columns)),
            implode(";\n    ", array_values($indexSql))
        );
//        ($tableName=='str') && dd($sql, $table->getIndexes());
//        dd($columns, $indexSql, $sql, $primaryKey);
//        try {
//            $result = $this->db->exec($sql);
////if ($tableName === 'obj') dd($sql);
//        } catch
//        (\Exception $exception) {
//            dd($exception, $sql, $columns, $indexSql);
//        }
//        ($tableName==='obj') && dd($sql, $result, $indexes);
//        dump($tableName, $sql);
        // still in a transaction, can't do this yet, wait until all tables are created.

//        assert($this->getPrimaryKey($tableName), "missing pk " . $sql);
//        if (str_contains($sql, 'student')) dd($sql, $result);
//        $tableName=='image' && dd($sql);
    }

    private function log($msg)
    {
        $this->logger->warning($msg);
        if ($this->logger) {
        }

    }

    public function close()
    {
//        $this->log(__METHOD__);
        // if a transaction, commit it
        if ($this->db->inTransaction()) {
            $this->commit();
        }
//        $this->query("VACUUM");
        unset($this->db);
        $this->db = new \PDO("sqlite:" . $this->filename);
//        $this->db->exec("VACUUM FULL;");
        $msg = 'ok';
        if ($truncateOnClose??false) {
            try {
                // we could wait a few seconds until its unlocked.  But this is questionable anyway
                if (!$queryOkay = $this->db->query($sql = "PRAGMA journal_mode=TRUNCATE")) {
                    $msg = 'query returned false ';
                }
            } catch (\PDOException $exception) {
                $queryOkay = false;
                $msg = $exception->getMessage();
            }
            if (!$queryOkay) {
                $this->logger->error($this->getFilename() . ' ' . $sql . ' ' . $msg);
            }
        }

    }

    public function beginTransaction()
    {
//        $this->log(__METHOD__);
//        assert(!$this->db->inTransaction(), "already in a transaction");
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

//    public function getItem(string $key): CacheItem
//    {
//        $item = new CacheItem();
//    }
    /**
     * Makes a query against the database.
     * @param string $sql The (potentially parametised) query to make.
     * @param array $variables Optional. The variables to substitute into the SQL query.
     * @return    \PDOStatement        The result of the query, as a PDOStatement.
     */
    public function query(string $sql, array $variables = []): \PDOStatement
    {
        assert(false, $sql);
        static $preparedStatements = [];
        // cache prepared statements
        if (empty($preparedStatements[$this->filename][$sql])) {
            try {
                $preparedStatements[$this->filename][$sql] = $this->db->prepare($sql);;
            } catch (\Exception $exception) {
                dump($exception, $sql, $this->filename, $variables);
                assert(false, $sql . " {$this->filename} " . $exception->getMessage());
            }
        }

//        dd($this->filename, $sql, $variables);
        $statement = $preparedStatements[$this->filename][$sql];
        $statement->execute($variables);

        return $statement; // fetchColumn(), fetchAll(), etc. are defined on the statement, not the return value of execute()
    }

    /**
     * Determines if the given key exists in the store or not.
     * @param string $key The key to test.
     * @param array $where filter the preload if we know we don't need them all, e.g. translation Keys
     * @return    bool    Whether the key exists in the store or not.
     */
    public function has(string $key, ?string $table = null, bool $preloadKeys = false, array $where = []): bool
    {
        $table ??= $this->currentTable;
        $pk = $this->getPrimaryKey($table);

        if ($preloadKeys) {
            // if this is too big, we can add a preloadWhere and selectively preload, e.g. translated string
            $tableKey = $table . '-' . md5(json_encode($where));
            if (empty($this->keyCache[$tableKey])) {
                $sql = "SELECT $pk from $table WHERE 1 ";
                foreach ($where as $itemKey => $value) {
                    $sql .= " and ($itemKey = :$itemKey)";
                }
                $this->keyCache[$tableKey] = $this->query($sql, $where)->fetchAll(PDO::FETCH_COLUMN);
                if (empty($this->keyCache[$tableKey])) {
                    $this->keyCache[$tableKey][] = null; // ??
                }
                $this->logger->info(sprintf("Preloaded %d keys in $table", count($this->keyCache[$tableKey])));
            }
            if (!is_array($this->keyCache[$tableKey])) {
                dd($this->keyCache, $tableKey);
            }
            return in_array($key, $this->keyCache[$tableKey]);
        }

        //
        // SELECT exists(SELECT 1 FROM users WHERE username = 'john_doe') AS row_exists;
        // https://stackoverflow.com/questions/9755860/valid-query-to-check-if-row-exists-in-sqlite3
//        dump('individual lookup for ' . $key); assert(false);
        return $this->query(
                "SELECT COUNT($pk) FROM $table WHERE $pk = :key",
                ["key" => $key]
            )->fetchColumn() > 0;
    }

    public function getByIndex(int $index, ?string $table = null): ?Item
    {
        if ($index === -1) {
            $index = random_int(0, $this->count($table));
        }
        $pk = $this->getPrimaryKey($table);
        $query = $this->query("select $pk from $table LIMIT 1 OFFSET $index");
        $result = $query->fetch(PDO::FETCH_COLUMN);
        return $result ? $this->get($result, $table) : null;
//        dd($result);

    }

    public function count(?string $table = null, array $where = []): ?int
    {
        $table ??= $this->currentTable;
        if (!$this->tableExists($table)) {
            return null;
        }
        assert(!$this->db->inTransaction(), "already in a transaction");
        $table ??= $this->currentTable;
        if (str_starts_with((string) $table, '@')) {
            return null;
        }
        assert($table);
        $pk = $this->getPrimaryKey($table);
        $sql = "SELECT COUNT($pk) FROM $table";
        if (count($where) > 0) {
            $sql .= " WHERE 1  ";
            foreach ($where as $key => $value) {
                $sql .= " and ($key = :$key)";
            }
        }
        $count = $this->query($sql, $where)->fetchColumn();
        return $count;
        try {
        } catch (\Exception $exception) {
            dd($sql, $exception);
        }
    }


    public function getSelectedTable(): ?string
    {
        return $this->currentTable;
    }

    public function hasTable($tableName): bool
    {
        return in_array($tableName, $this->getTableNames());

    }

    /**
     * Gets a value from the store.
     * @param string $key The key to store the value under.
     * @return    string    The value to store.
     */
    public function get(string $key, ?string $tableName = null, ?callable $callback = null): ?Item // string|object|array|null
    {
        $tableName ??= $this->currentTable;
        $keyName = $this->getPrimaryKey($tableName);
        $results = $this->query(
            sprintf("SELECT * FROM %s WHERE $keyName = :key;", $tableName),
            ["key" => $key]
        )->fetchObject();
        if ($results) {
//            dump($results, $tableName);
//            assert(false);
            $marking = $results->marking ?? null;
            // hack for workflow, ugh. Could generalize with properties, casting, etc.
            // the _raw is really the data!
            $raw = $results->_raw;
            // there may be a way in sqlite to return this already parsed.
            if (is_string($raw)) {
                $raw = json_decode($raw);
            }
            // we need the values in raw from the properties
                return new Item($raw, $key, $tableName, $this->getPixieCode(), marking: $marking);
        } else {
            if ($callback !== null) {
                $callback($key, $tableName);
                dd($callback);
            }
            return null;
        }
    }

    /**
     * Sets a value in the data store.
     * @param string $key The key to set the value of.
     * @param string $value The value to store.
     * @param string $propertyName If set, update the property, not _raw
     */
    public function set(array|object|string|null $value,
                        ?string              $tableName = null,
                        string|int|null     $key = null,
                        ?string              $propertyName = null,
                        string              $mode = 'replace',
                        array               $where = [], // for preload
                        // properties, not raw, e.g. locale and _t
                        array $properties = []
        // _raw, if patch then read first and merge
    ): mixed
    {
        assert(false, "@todo: move to pixieEm\n$key\n\n" . json_encode($value, JSON_PRETTY_PRINT));
        // this isn't true for image and translation tables
//        SurvosUtils::assertKeyExists(PixieTranslationService::TRANSLATION_KEY, $value);
//        dump($value[PixieTranslationService::TRANSLATION_KEY], $value); assert(false);

        $previousTable = $this->currentTable;
        $tableName ??= $this->currentTable;
        assert($tableName, "missing tableName in call");
        if (empty($properties)) {
            assert(is_object($value) || is_iterable($value), "if property is not set, must be iterable");
        }
        static $preparedStatements = [];
//        if ($tableName<>'source')  dump(get_defined_vars());

//    $schema = $this->inspectSchema();
//    assert(array_key_exists($tableName, $schema), "no table $tableName in schema " . $this->getFilename());
        /** @var Table $table */
//    $table = $schema[$tableName];
//    assert($table, "No table $tableName");
        $table = $this->getTable($tableName);
        $keyName = $table->getPkName();
        $props = $table->getPropertiesByCode();

        // remove extra columns from json with https://sqlite.org/forum/info/5928225848d0409f

        // e.g. updating marking
        if ($mode === self::MODE_PATCH) {
            $data = $this->get($key, $tableName)->getData();
            $value = array_merge((array)$data, $value);
        }
        if (true)
        {
            // properties MUST be defined as their own column, so they don't overwrite _raw or need updating raw
            foreach ($properties as $propertyName => $propertyValue) {
                if ($prop = $props[$propertyName]?? null) {
//                    dd($prop);
                }
                unset($value[$propertyName]);
            }
            $properties[$keyName] = $key??$value[$keyName];
            $key = $properties[$keyName]??null;
            if (($tableName=='obj') && in_array($key, ['638384caab8f2aac','5671XX'])) {
                assert(false, $key);
                dd($properties);
            }
            // if value is null, don't update raw, only the properties like translations
            if ($value) {
                $properties['_raw'] = $value;
            }
            $keys = array_keys($properties);
            $sqlCacheHash = hash('xxh3', $tableName . json_encode($keys));
            if (!$stmt = $preparedStatements[$sqlCacheHash]??null) {
                $sql = sprintf("
                    INSERT OR REPLACE INTO $tableName(%s) 
                        VALUES(%s)
                ",
                    join(', ', $keys),
                    join(',', array_map(fn(string $s) => ":$s", $keys))
                );

                try {
                    $stmt = $this->db->prepare($sql)    ;
                } catch (\Exception $exception) {
                    dd($sql, $exception, $keys);
                }
                // cache for future
                $preparedStatements[$sqlCacheHash]=$stmt;
            }
            foreach ($properties as $name => $value) {
                $params[$name] = !is_iterable($value) ? $value : json_encode($value);
            }
            try {
                assert(is_string($params[$keyName]) || is_int($params[$keyName]), $params[$keyName]);

//                ($tableName<>'source') && dump(json_decode($params['_raw']), $tableName);
                $results = $stmt->execute($params);
//                dd($stmt->queryString, $stmt, $params);
            } catch (\Exception $exception) {
                dd($sql, $exception, $properties, $params);
            }
//            dd($sql, $params);
        } else {
            assert(false, "this code may no longer be valid.");
            // update _raw, the json blob

            // @todo: strings require a key, probably another method
            if (is_object($value)) {
                $value = (array)$value;
            }
            if (is_array($value) && !array_key_exists($keyName, $value)) {
                dump($keyName, $value);
                throw new \LogicException("Missing key $keyName in $tableName:\n " . implode("\n", array_keys($value)));
                dd($table, $value, $keyName, $tableName);
            }
            $value = (array)$value; // if JSON

            assert(array_key_exists($keyName, $value),
                "$keyName missing $tableName in " . implode(',', array_keys($value)));
            if (!$key) {
                $key = is_array($value) ? $value[$keyName] : $value->$keyName;
//            $accessor->getValue($value, $keyName);
//            dd($key, $keyName, $value);
                // must come from the value blob
            }
            // hash the properties to cache all prepared statements
            if (empty($preparedStatements[$tableName])) {
                $preparedStatements[$tableName] =
                    $this->db->prepare("
                    INSERT OR REPLACE INTO $tableName($keyName, _raw) 
                        VALUES(:key, :value)
                ");
            }
//        dd($value, $key, $tableName);
            $statement = $preparedStatements[$tableName];
            assert($this->db->inTransaction(), "not in a transaction for the update");
            assert(!array_key_exists('_raw', $value), "Do not add _raw to _raw!");
            try {
                $results = $statement->execute(
                    $params = [
                        'key' => $key,
                        "value" => is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES)
                    ]);
//                dd($value, $statement, $results);
//                if ($mode === self::MODE_PATCH) dd($key, $value);
//            $this->db->commit();
//        assert(false);

            } catch (\Exception $exception) {
                if ($exception->getCode() === 'HY000') {
                    // locked
                    throw new \Exception($this->filename . " is locked \n\n" . $exception->getMessage());
                } else {
                    dd($exception, $value, $preparedStatements, $tableName);
                }
            }
            if (!$results) {
                dd("Error: " . $statement->errorInfo()[2]);
            }
        }
        $tableKey = $tableName . '-' . md5(json_encode($where));
        // hmm, this always happens, even if there's no preload...
        $this->keyCache[$tableKey][] = $key;
        $this->currentTable = $previousTable;

        return $results;
    }

    /**
     * Deletes an item from the data store.
     * @param string $key The key of the item to delete.
     * @return    bool    Whether it was really deleted or not. Note that if it doesn't exist, then it can't be deleted.
     */
    public function delete(string $key, ?string $table = null): bool
    {
        $tableName = $table ?? $this->currentTable;
        return $this->query(
                sprintf("DELETE FROM %s WHERE %s = :key;",
                    $tableName,
                    $this->getPrimaryKey($tableName)),
                ["key" => $key]
            )->rowCount() > 0;
    }

    /**
     * Empties the store.
     */
    public function clear(): void
    {
        $this->query("DELETE FROM $this->currentTable;");
    }

    public function getSql(string $table,
                           array $where = [],
                           array  $order = [],
                           int    $startingAt = 0,
                           int    $max = 0,
                           bool   $keyOnly = false,
                           array  $whereExtra = [],
                           ?array $pks = [],
                           array  $columns = ['*']
    ): array
    {
        $pk = $this->getPrimaryKey($table);
        // @todo: only prepare the statement once
        $sql = "select " . ($keyOnly ? $pk : implode(',', $columns)) .
            " from " . ($table ?? $this->currentTable);
        $params = [];

        // @todo: prepared statement and bind values.
        $sql .= " where 1=1 ";

        // https://stackoverflow.com/questions/920353/can-i-bind-an-array-to-an-in-condition-in-a-pdo-query
        if (is_array($pks) && count($pks)) {
            // create key1, key2, for the IN statement and set the params
            foreach ($pks as $idx => $pkValue) {
                $pkKeys[] = ":" . ($keyName = "key$idx");
                $params[$keyName] = $pkValue;
            }
            $sql .= "and $pk in (" . implode(',', $pkKeys) . ")";
        }


        // dexie format: .where('myField').equals(1) .where('myField').gt(5)
        // where returns a collection (promise) with no objects. https://dexie.org/docs/Collection/Collection
        // pass a tuple with operator?  or a string?  I think that's how api grid works.
        foreach ($where as $key => $value) {
            if ($value === null) {
                $sql .= " and ($key IS NULL)";
            } elseif (is_array($value)) {
                $sql .= " and " . $key . " in (:$key)";
                $params[$key] = $value;
            } else {
                $sql .= " and " . $key . " = :$key";
                $params[$key] = $value;
            }
        }

        // marking == NULL
        // key in (:ids)
        foreach ($whereExtra as $fragment => $fragmentValues) {
            $sql .= " and $fragment ";
            $params = array_merge($params, $fragmentValues);
        }
        if (count($order) == 0) {
            $order = [$this->getPrimaryKey($table) => 'ASC'];
        }
        foreach ($order as $key => $value) {
            $sql .= " order by $key $value";
        }
        if ($startingAt > 0) {
            $sql .= " OFFSET " . $startingAt;
        }
        if ($max > 0) {
            $sql .= " limit " . $max;
        }
        return [$sql, $params];

    }

    public function iterate(?string $table = null,
                            ?array $where = [],
                            int    $max = 0,
                            array  $order = [],
                            ?bool  $associative = null,
                            int    $depth = 512,
                            int    $flags = PDO::FETCH_ASSOC,
                            ?array $whereExtra = [],
                            ?array $pks = null,
    ): \Generator
    {
        $table ??= $this->currentTable;
        assert($table, "no table configured");
        $pkName = $this->getPrimaryKey($table);
//        $keyOnly = true; // this iterates one at a time
        $keyOnly = false;

        if (is_array($pks) && !count($pks)) {
            $this->logger->warning( "are you sure you want to pass 0 pks?");
        }
        [$sql, $params] = $this->getSql($table, $where, $order, max: $max,
            keyOnly: $keyOnly,
            pks: $pks,
            whereExtra: $whereExtra,
        );
        $jsonFields = $this->getTable($table)->getJsonFields();

        // https://stackoverflow.com/questions/78623214/using-a-generator-to-loop-through-an-update-a-table-in-pdo

        try {
            $sth = $this->query($sql, $params);
            $all = $sth->fetchAll($flags);
        } catch (\Exception) {
            dd($sql, $params);
        }
//        dd($sql, $params, $all, $keyOnly);
        if (count($all) === 0) {
            return;
        }

        foreach ($all as $idx => $row)
//        foreach ($sth as $idx => $row)
//        while ($row = $sth->fetch(PDO::FETCH_ASSOC))
        {

//            ($table=='tag') && dd(get_defined_vars(), $row, $idx, $table, $where, count($pks??[]));
            // @todo: figure out how these got in here!
            if (!$row) {
                continue;
            }
            if ($keyOnly) {
                $rowQuery = $this->query("select * from $table where " . $pkName . " = :pk;", ["pk" => $row[$pkName]]);
                $row = $rowQuery->fetch(PDO::FETCH_ASSOC);
//                ($table == 'mat') && assert(false);

            }
            // can 'value' be configured?  _raw
            // @todo: lax, strict, none (handle _raw, _value, etc. )
            // value is deprecated!
            if ($value = $row['_raw'] ?? null) {
                $value = json_decode((string) $value, $associative, $depth, $flags);
                unset($row['_raw']);
                $value = array_merge((array)$value, $row);
            }
            // now merge with the keys
//            dd($value);
            // check, or is the value always json?
            $key = $value[$pkName] ?? null;
//            dd($value, $table, $key, $row);
            $item = $value ? new Item((object)$value, $key, $table, $this->getPixieCode()) : null;
//dd($item, $value);
            if (!$row) { dd($item, $value, $key, $this->getFilename(), $table); }
//            dd($row, $item, $pkName);
            yield $row[$pkName] => $item;
        }
        $sth->closeCursor(); //
    }

    public function iterateOver(?string $table, callable $callback, ?bool $associative = null, int $depth = 512, int $flags = 0): array
    {
        $results = [];
        foreach ($this->iterate($table, $associative, $depth, $flags) as $key => $value) {
            $results[$key] = $callback($key, $value);
        }
        return $results;
    }

    public function getKeys(?string $tableName = null): array
    {
        $tableName ??= $this->currentTable;
        assert($tableName, "no table configured");
        $primaryKey = $this->getPrimaryKey($tableName);
        $sth = $this->query("select $primaryKey from $tableName");
        return $sth->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string>
     */
    public function getTableNames(): array
    {
        // this does a query!!
        assert(false, $this->filename);
        $sth = $this->query("SELECT name FROM sqlite_master WHERE type='table';");
        $tableNames = $sth->fetchAll(PDO::FETCH_COLUMN);
        return $tableNames;
    }

    /**
     * @return array<Table>
     */
    public function getTables(): array
    {
        return $this->getConfig()->getTables();
    }

    public function getTable(string $tableName): ?Table
    {
        return $this->getConfig()->getTables()[$tableName];

    }

    public function tableExists(string $table): bool
    {
        $sth = $this->query("SELECT * FROM sqlite_master WHERE name='$table' and type='table';");
        return count($sth->fetchAll(PDO::FETCH_COLUMN)) > 0;
    }

    public function getCounts(string $column, ?string $table = null, int $limit = 15): array
    {
        $tablename = $table ?? $this->currentTable;
        $sql = "SELECT COUNT(rowid) as count, $column as value FROM $tablename GROUP BY $column";
        $sql .= " ORDER BY COUNT(rowid) DESC";
        if ($limit) {
            $sql .= " LIMIT " . $limit;
        }

        $sth = $this->query($sql);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getIndexes(?string $table = null): array
    {
        $sth = $this->db->query($sql = "SELECT * FROM sqlite_master where type='index'");
        $indexes = [];
        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $item) {
            // auto-inc has no sql
            $itemTableName = $item['tbl_name'];
            if ($item['sql'] && ($itemTableName === $table)) {
                $indexes[] = str_replace($itemTableName . '_', '', $item['name']);
            }

        };
        return $indexes;

    }

    public function commit()
    {
//        $this->log(__METHOD__);
        // we could check the db, too
        assert($this->db->inTransaction(), "NOT in a transaction");
        $this->db->commit();
        assert(!$this->db->inTransaction(), "STILL in a transaction");
//        dump(__METHOD__ . ' in ' . $this->filename);
//        $this->query("VACUUM"); // ?

    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();

    }

}
