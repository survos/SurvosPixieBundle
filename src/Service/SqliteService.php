<?php

/**
 * This is the basis of a migration tool, as it compares the existing database to some schema.
 *
 *
 */
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\DriverManager;
use \PDO;

class SqliteService
{
    public function __construct()
    {
    }


    public function playWithSqliteSchema(string $sourceReferences)
    {

        if (!file_exists($sourceReferences)) {
            // https://write.corbpie.com/sqlite3-with-php-creating-a-database-and-connecting-to-it/
            $db = new PDO('sqlite:' . $sourceReferences, '', '', array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ));
        }
        $connectionParams = [
            'path' => $sourceReferences,
            'driver' => 'pdo_sqlite',
        ];
            $conn = DriverManager::getConnection($connectionParams);
            $sm = $conn->createSchemaManager();
            $fromSchema = $sm->introspectSchema();

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


//        $queries = $schemaDiff->toSql($myPlatform); // queries to get from one to another schema.

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

        $myPlatform = $conn->getDatabasePlatform();
        $diffs = $myPlatform->getAlterSchemaSQL($schemaDiff);

        $sc->introspectSchema();
        $newSchema = $sm->introspectSchema();
        foreach ($newSchema->getTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $tables[$table->getName()]['columns'][] = $column->getName();
            }
        }


        return [$tables, $diffs];

//        foreach ($schemaDiff->toSql($myPlatform) as $sql) {
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
