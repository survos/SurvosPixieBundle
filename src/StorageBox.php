<?php

namespace Survos\PixieBundle;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Doctrine\DBAL\DriverManager;
use JetBrains\PhpStorm\NoReturn;
use \PDO;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Model\Index;
use Survos\PixieBundle\Model\Table;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use function Symfony\Component\String\u;

/*
 *
 * https://gist.github.com/sbrl/c3bfbbbb3d1419332e9ece1bac8bb71c
 *
 * a multi-table, multi-lingual Sqlite key-value data store.
 * @license Apache 2.0
 */

class StorageBox
{
    /**
     * The SQLite database connection.
     * @var \PDO
     */
    private \PDO $db;
    private array $tables = [];
    private bool $inTransaction = false;

//    private array $regexRules=[];
    /**
     * Initialises a new store connection.
     * @param string $filename The filename that the store is located in.
     * @param array $tablesToCreate The ADDITIONAL tables to create with writing.  Others may already exist.
     */
    #[NoReturn] function __construct(private string                    $filename,
                                     array &$data, // debug data, passed from Pixie
                                     private array                     $tablesToCreate = [],
                                     private array                     $regexRules = [],
                                     private ?string                   $currentTable = null,
                                     private ?int                      $version = 1,
                                     private string                    $valueType = 'json', // eventually jsonb
                                     private bool                      $temporary = false, // nyi
                                     private readonly ?LoggerInterface $logger = null,
                                     private readonly ?PropertyAccessorInterface $accessor = null,
                                     private array $formatters = [],
                                     private readonly ?Stopwatch $stopwatch = null,
    )
    {
        $path = $this->filename;


        // Enable WAL mode to fix locking issues?
//        $this->beginTransaction();
//        $sqlite3 = new \SQLite3($this->filename);
//        dd($this->filename, $path);
        // HACK: This might not work on some systems, because it depends on the current working directory

        // PDO creates the db if it doesn't exist, so check after
        if (!file_exists($path)) {
            try {
                $this->db = new \PDO("sqlite:" . $path);
            } catch (\PDOException $e) {
                dd($path, $e->getMessage());
            }
            $this->db->query("PRAGMA journal_mode=WAL");
            $this->db->query("PRAGMA lock_timeout=5");
            $this->db->setAttribute(PDO::ATTR_TIMEOUT, 10);
//            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            try {
                $this->db = new \PDO("sqlite:" . $path);
            } catch (\PDOException $e) {
                dd($path, $e->getMessage());

            }
        }

        $sth = $this->db->query($sql = "SELECT name FROM sqlite_master where type='table'");
        $this->tables = $sth->fetchAll(PDO::FETCH_COLUMN); // load the existing tables

        $this->beginTransaction();
        foreach ($this->tablesToCreate as $table => $indexes) {
//            dd($indexes, array_is_list($indexes));
//            dd($this->tablesToCreate, array_is_list($this->tablesToCreate));
            if (!in_array($table, $this->tables)) {
                $this->createTable($table, $indexes, $this->valueType);
                $this->tables[] = $table;
            }
        }
        $this->commit();
//        $tables = $this->inspectSchema();
//        dd($this->tablesToCreate, $this->tables, current($this->tablesToCreate));
        // defaults to first table?
//        $this->currentTable = current($this->tablesToCreate)??current($this->tables);
//        $this->commit();
//        $this->beginTransaction();
        assert(!$this->db->inTransaction());
    }

    public function addFormatter(callable $callable)
    {
        $this->formatters[] = $callable;
    }

    // @todo: make this an event listener?
    // given the HEADER array, map the key names, for array_combine or csv getRecords
    public function mapHeader(array $header, string $tableName = null, array $regexRules=[]): array
    {
        $tableName = $tableName ?? $this->currentTable;
        // $fieldName is the attribute (json) or column name (csv)
        $newHeaders = [];
//        $regexRules = $this->regexRules[$tableName] ?? [];
//        dd(tableName: $tableName, regex: $regexRules);
        foreach ($header as $fieldName) {
            $newFieldName = $fieldName;
            foreach ($regexRules as $regex => $value) {
//                assert($regex, $regex);
//                dump($regex, $this->regexRules);
//                assert(preg_match($regex, ''), $regex);
                if (preg_match($regex, $fieldName, $mm)) {
                    $newFieldName = $value; // @todo: apply a function or rule
                    break; // match first rule only
                }
            }
            $newHeaders[] = u($newFieldName)->snake()->toString();
        }
        return $newHeaders;
    }

    public function select(string $tableName): self
    {
        if (!in_array($tableName, $this->tables)) {
            throw new \LogicException("$tableName is not in tables array");
        };
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

    public function getPrimaryKey(?string $tableName=null): string
    {
        $tableName = $tableName ?? $this->currentTable;
        return $this->inspectSchema()[$tableName]->getPkName();

    }

    public function inspectSchema(string $filename = null): array
    {
        $filename = $filename ?? $this->getFilename();

        static $tables=[];
        if (array_key_exists($filename, $tables) && count($tables[$filename])) {
            return $tables[$filename];
        }

        // is it necessary to open it again, or can we get this from the PDO?
        $connectionParams = [
            'path' => $filename,
            'driver' => 'pdo_sqlite',
        ];
            $conn = DriverManager::getConnection($connectionParams);
            $sm = $conn->createSchemaManager();
            $fromSchema = $sm->introspectSchema();

            $tables[$filename] = [];
            foreach ($fromSchema->getTables() as $schemaTable) {
                $primaryIndex = $schemaTable->getIndex('primary');
                $table = new Table($schemaTable->getName(), $schemaTable->getColumns(), join('-', $primaryIndex->getColumns()));
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
    static function getIndexDefinitions(string|array $indexConfig): array
    {
        $indexes = [];
        if (is_string($indexConfig)) {
            $indexConfig = array_map(fn($key) => $key ? trim($key) : assert($key, "empty key! " . $indexConfig),
                explode(',', $indexConfig));
        }

        $primaryIndex = 0; // by default
        foreach ($indexConfig as $indexId => $indexName) {
            if (is_array($indexName)) {
                dd($indexConfig, $indexName, $indexId);
                // parse type and maybe regex rule
                continue;
            }

            // pk is first unique key (or first key).  option to use rowid?
            if (!$primaryIndex && str_starts_with($indexName, '&')) {
                $primaryIndex = $indexId;
            }
            if (str_contains($indexName, '|')) {
                [$indexName, $type] = explode('|', $indexName);
            } else {
                $type = 'TEXT';
            }

            // break the string up into the index model
            $index = new Index($indexName, $type, isUnique: str_starts_with($indexName, '&'));
            $indexes[] = $index;
        }

        // set primary and unique
        $indexes[$primaryIndex]->isPrimary=true;

        return $indexes;

    }

    /**
     * @param string $tableName
     * @param string|array Index[] $indexes
     * @param string $valueType
     * @return void
     */
    public function createTable(string $tableName, string|array $indexes, string $valueType = 'JSON'): void
    {


        // if no column is flagged as unique, assume the first key
        $indexes = $this->getIndexDefinitions($indexes);

        // more json examples at https://www.sqlitetutorial.net/sqlite-json/
        $indexSql = [];
        // @todo: improve PK: https://www.sqlitetutorial.net/sqlite-primary-key/
        // a generated column can't be the primary key, but interesting: https://sqlite.org/forum/info/5928225848d0409f
        $primaryKey = 'key TEXT PRIMARY KEY';
        $columns = [
            'value TEXT NOT NULL',
        ];

        /**
         * @var int $indexId
         * @var Index $index
         */
        foreach ($indexes as $indexId => $index) {
            $type = $index->type;
            $name = $index->propertyName;
            // @todo: handle auto-increment
            if ($index->isPrimary) {
                $primaryKey = "$name $type PRIMARY KEY";
            } else {
                // also see json_each example at https://sqlime.org/#deta:m97q76wmvzvd
                // create a generated column so that it all happens internally
                // json_extract('{"a":"xyz"}', '$.a') → 'xyz'
//                https://www.sqlite.org/gencol.html

                // d INT GENERATED ALWAYS AS (a*abs(b)) VIRTUAL,
                $columns[] = "$name $type  GENERATED ALWAYS AS (json_extract(value, '\$.$name')) STORED /* @searchable */";
                $indexSql[] = "create index {$tableName}_{$name} on $tableName($name) /* @filterable */";
            }
        }
        array_unshift($columns, $primaryKey);

        $sql = sprintf("CREATE TABLE IF NOT EXISTS %s (%s); %s", $tableName,
            join(",\n", $columns),
            join(";\n", $indexSql));
        try {
            $result = $this->db->exec($sql);
        } catch (\Exception $exception) {
            dd($exception, $sql, $columns, $indexSql);
        }
//        if (str_contains($sql, 'student')) dd($sql, $result);
//        dd($sql);
    }

    private function log($msg)
    {
        $this->logger->warning($msg);
        if ($this->logger) {
        }

    }

    public function close()
    {
        $this->log(__METHOD__);
        // if a transaction, commit it
        if ($this->db->inTransaction()) {
            $this->commit();
        }
        unset($this->db);
    }

    public function beginTransaction()
    {
        $this->log(__METHOD__);
        assert(!$this->db->inTransaction(), "already in a transaction");

        $this->db->beginTransaction();
    }

    public function getFilename(): string
    {
        return realpath($this->filename);
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
    protected function query(string $sql, array $variables = []): \PDOStatement
    {
        static $preparedStatements = [];
        // cache prepared statements
        if (empty($preparedStatements[$sql])) {
            try {
                $preparedStatements[$sql] = $this->db->prepare($sql);;
            } catch (\Exception $exception) {
                dd($exception, $sql);
            }
        }
        $statement = $preparedStatements[$sql];
        $statement->execute($variables);

        return $statement; // fetchColumn(), fetchAll(), etc. are defined on the statement, not the return value of execute()
    }

    /**
     * Determines if the given key exists in the store or not.
     * @param string $key The key to test.
     * @return    bool    Whether the key exists in the store or not.
     */
    public function has(string $key, string $table = null, bool $preloadKeys = false): bool
    {
        static $keyCache = [];
        $table = $table ?? $this->currentTable;
        $pk = $this->getPrimaryKey($table);

        if ($preloadKeys) {
            if (empty($keyCache[$table])) {
                $keyCache[$table] = $this->query("SELECT $pk from $table")->fetchAll(PDO::FETCH_COLUMN);
            }
            return in_array($key, $keyCache[$table]);
        }

        //
        // sqlite EXISTS might be faster
        return $this->query(
                "SELECT COUNT($pk) FROM $table WHERE $pk = :key",
                ["key" => $key]
            )->fetchColumn() > 0;
    }

    public function count(string $table = null): int
    {
        assert(!$this->db->inTransaction(), "already in a transaction");
        $table = $table ?? $this->currentTable;
        $pk = $this->getPrimaryKey($table);
            $count = $this->query($sql = "SELECT COUNT($pk) FROM $table")->fetchColumn();
            $this->log("$table has $count");
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

    /**
     * Gets a value from the store.
     * @param string $key The key to store the value under.
     * @return    string    The value to store.
     */
    public function get(string $key, string $table = null): string|object|array|null
    {
        $tableName = $table?? $this->currentTable;
        $keyName = $this->getPrimaryKey($tableName);
        $results = $this->query(
            sprintf("SELECT * FROM %s WHERE $keyName = :key;", $tableName),
            ["key" => $key]
        )->fetchObject();
//        dd($results);
        return $results ?: null;
//        return json_decode($results, true);
    }

    /**
     * Sets a value in the data store.
     * @param string $key The key to set the value of.
     * @param string $value The value to store.
     */
    public function set(array|object $value, string $tableName = null, string|int|null $key=null): mixed
    {
        static $preparedStatements = [];
        $tableName = $tableName ?? $this->currentTable;

        /** @var Table $table */
        $table = $this->inspectSchema()[$tableName];
        assert($table, "No table $tableName");
        $keyName = $table->getPkName();
        // @todo: strings require a key, probably another method
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (is_array($value) && !array_key_exists($keyName, $value)) {
            throw new \LogicException("Missing key $keyName in document " . join("\n", array_keys($value)));
            dd($table, $value, $keyName, $tableName);
        }
        $value = (array)$value; // if JSON
        assert(array_key_exists($keyName, $value),
            "$keyName missing $tableName in " . join(',', array_keys($value)));
        if (!$key) {
            $key = is_array($value) ? $value[$keyName] : $value->$keyName;
//            $accessor->getValue($value, $keyName);
//            dd($key, $keyName, $value);
            // must come from the value blob
        }
        if (empty($preparedStatements[$tableName])) {
            $preparedStatements[$tableName] =
                $this->db->prepare("
                    INSERT OR REPLACE INTO $tableName($keyName, value) 
                        VALUES(:key, :value)
                ");
        }
        $statement = $preparedStatements[$tableName];
        // @todo: defer these in a batch
//        $this->db->beginTransaction();
        assert($this->db->inTransaction());
            $results = $statement->execute(
                $params = [
                    'key' => $key,
                    "value" => is_string($value) ? $value : json_encode($value)
                ]);
//            $this->db->commit();
//        assert(false);

        try {
        } catch (\Exception $exception) {
            dd($exception, $params, $preparedStatements, $tableName);
        }
        if (!$results) {
            dd("Error: " . $statement->errorInfo()[2]);
        }

        return $results;
    }

    /**
     * Deletes an item from the data store.
     * @param string $key The key of the item to delete.
     * @return    bool    Whether it was really deleted or not. Note that if it doesn't exist, then it can't be deleted.
     */
    public function delete(string $key, string $table = null): bool
    {
        $tableName = $table?? $this->currentTable;
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

    public function getSql(string $table, array $where=[], int $max=0): array
    {
        // @todo: only prepare the statement once
        $sql = "select * from " . ($table ?? $this->currentTable);

        // @todo: prepared statement and bind values.
        $sql .= " where 1=1";

        // dexie format: .where('myField').equals(1) .where('myField').gt(5)
        // where returns a collection (promise) with no objects. https://dexie.org/docs/Collection/Collection
        // pass a tuple with operator?  or a string?  I think that's how api grid works.
        $params = [];
        foreach ($where as $key => $value) {
            $sql .= " and " . $key . " = :$key";
            $params[$key] = $value;
        }
        if ($max > 0) {
            $sql .= " limit " . $max;
        }
        return [$sql, $params];

    }
    public function iterate(string $table = null,
                            ?array $where = [],
                            ?bool  $associative = null,
                            int $depth = 512,
                            int    $flags = PDO::FETCH_ASSOC,
                            int    $max = 0
    ): \Generator
    {
        $table = $table??$this->currentTable;
        assert($table, "no table configuredd");
        $pkName = $this->getPrimaryKey($table);
        [$sql, $params] = $this->getSql($table, $where, $max);

//        dd($sql, $params);

        // https://stackoverflow.com/questions/78623214/using-a-generator-to-loop-through-an-update-a-table-in-pdo
        $sth = $this->query($sql, $params);
        $all = $sth->fetchAll($flags);

        foreach ($all as $idx => $row)
//        foreach ($sth as $idx => $row)
//        while ($row = $sth->fetch(PDO::FETCH_ASSOC))
        {
            $value = $row['value'];
//            dump($value);
            $value = json_decode($value, $associative, $depth, $flags);
            // now merge with the keys
            unset($row['value']);
            $value = array_merge((array)$value, $row);
//            dd($value);
            // check, or is the value always json?
            yield $row[$pkName] => $value;
        }
    }

    public function iterateOver(string $table = null, callable $callback, ?bool $associative = null, int $depth = 512, int $flags = 0): array
    {
        $results = [];
        foreach ($this->iterate($table, $associative, $depth, $flags) as $key => $value) {
            $results[$key] = $callback($key, $value);
        }
        return $results;
    }

    public function getKeys(string $table = null): array
    {
        $sth = $this->query("select key from " . ($table ?? $this->currentTable));
        return $sth->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getTables(): array
    {
        $sth = $this->query("SELECT name FROM sqlite_master WHERE type='table';");
        return $sth->fetchAll(PDO::FETCH_COLUMN);
    }

    public function tableExists(string $table): bool
    {
        $sth = $this->query("SELECT * FROM sqlite_master WHERE name='$table' type='table';");
        return count($sth->fetchAll(PDO::FETCH_COLUMN)) > 0;
    }

    public function getCounts(string $column, string $table = null, int $limit=15): array
    {
        $tablename = $table ?? $this->currentTable;
        $sql = "SELECT COUNT(rowid) as count, $column as value
FROM $tablename
GROUP BY $column
";
$sql .= " ORDER BY COUNT(rowid) DESC";
        if ($limit) {
            $sql .= " LIMIT " . $limit;
        }

        $sth = $this->query($sql);
        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getIndexes(string $table = null): array
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
        $this->log(__METHOD__);
        // we could check the db, too
        assert($this->db->inTransaction(), "NOT in a transaction");
        $this->db->commit();
        assert(!$this->db->inTransaction(), "STILL in a transaction");
    }

}
