<?php

namespace Survos\PixieBundle\Tests;


// this is in APP, waiting for https://github.com/symfony/symfony/discussions/57587 to move to bundle

use League\Csv\Exception;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\SqliteService;
use Survos\PixieBundle\StorageBox;
use Survos\PixieBundle\StorageBoxInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

class PixieTest extends KernelTestCase
{
    const FILENAME='test.pixie.db';
    const MOVIE_TABLE_NAME='movie';
    #[Test]
    public function createTables(): void
    {
        $kernel = self::bootKernel([
            'environment' => 'test',
        ]);
        $this->assertSame('test', $kernel->getEnvironment());
        $movieTableName = self::MOVIE_TABLE_NAME;

        // $routerService = static::getContainer()->get('router');
         $kvService = static::getContainer()->get(PixieService::class);
            $kvService->destroy($filename = self::FILENAME);

         $kv = $kvService->getStorageBox($filename, [
             self::MOVIE_TABLE_NAME => 'imdb_id|int,name'
         ]);
        $this->assertCount(1, $kv->getTables(), "bad table count");
         $kv->select(self::MOVIE_TABLE_NAME);

         $this->assertEquals(0, $kv->count($movieTableName), self::MOVIE_TABLE_NAME . " should be empty");

        $tables = $kv->inspectSchema();
        $this->assertContains($movieTableName, array_keys($tables));
        /** @var Table $movieTable */
        $movieTable = $tables[$movieTableName];
        $this->assertEquals(Table::class, get_class($movieTable));
        $this->assertSame('imdb_id', $kv->getPrimaryKey());
        $this->assertSame($kv->getPrimaryKey(), $movieTable->getPkName());

        $kv->close();
        return;
        assert(false, "stopped");

        // @todo: end the exception expectation
        $this->expectException(\LogicException::class);
        $kv->select('badTable');

    }

    private function createMovieDatabase(): StorageBox
    {
        $kernel = self::bootKernel([
            'environment' => 'test',
        ]);

        $kvService = static::getContainer()->get(PixieService::class);
        $kv = $kvService->getStorageBox(self::FILENAME, [
//            self::MOVIE_TABLE_NAME => 'imdb_id|int,name'
        ]);
        assert(false);
        return $kv;
    }

    #[Test]
    public function parseIndex(): void
    {
            $indexArray = StorageBox::getIndexDefinitions('id,name|text');
        foreach ($indexArray as $index) {
            $indexes[$index->propertyName] = $index;
        }
        $this->assertTrue($indexes['id']->isPrimary);
        $this->assertNotTrue($indexes['name']->isPrimary, json_encode($indexes['name']));
        $this->assertSame('TEXT', $indexes['name']->type, json_encode($indexes['name']));
        $this->assertSame('TEXT', $indexes['id']->type);


    }

    #[Test]
    #[Depends('createTables')]
    public function addData(): void
    {
        $kvService = static::getContainer()->get(PixieService::class);
        $kv = $kvService->getStorageBox(self::FILENAME);
//        $kv = $this->createMovieDatabase();
        $versionString = $kv->getVersion(); // @todo: check against config

        $kv->select(self::MOVIE_TABLE_NAME);
        self::assertSame(self::MOVIE_TABLE_NAME, $kv->getSelectedTable());
        $title = "Rainman";
        $movie = $kv->get(1);
        $this->assertNull($movie);

        self::assertFalse($kv->has(2));
        self::assertFalse($kv->has(2, preloadKeys: true));

        $kv->beginTransaction();
        $kv->set(['imdb_id' => 1, 'name' => $title, 'year' => 1985]);
        $kv->commit();
        $this->assertEquals(1, $kv->count(), self::MOVIE_TABLE_NAME . " have 1 record");

        $movie = (array)$kv->get(1);
        $this->assertEquals($movie['name'], $title);
        $deleted =  $kv->delete(1);
        self::assertTrue($deleted);
        $deleted =  $kv->delete(1);
        self::assertFalse($deleted);

        $kv->clear();
        $this->assertEquals(0, $kv->count());
        $kv->close();

        $kvService->destroy(self::FILENAME);

    }

    #[Test]
    public function importJsonData(): void
    {
//        $kvService = static::getContainer()->get(PixieService::class);

        /** @var PixieImportService $importService */
        $importService = static::getContainer()->get(PixieImportService::class);
        $momaTable = 'moma.pixie.db';

        $configFilename = __DIR__ . '/Fixtures/config/test-moma.yaml';
        $this->assertTrue(file_exists($configFilename), $configFilename);
        $configData = Yaml::parseFile($configFilename);
        $testDataDir = __DIR__ . '/Fixtures/testdata';
        $importService->import($configData, $momaTable, $testDataDir);
    }

    private function import(string $code): StorageBox
    {
        /** @var PixieImportService $importService */
        $importService = static::getContainer()->get(PixieImportService::class);
        $table = "$code.pixie.db";

        $configFilename = __DIR__ . "/Fixtures/config/$code.yaml";
        $this->assertTrue(file_exists($configFilename), $configFilename);
        $configData = Yaml::parseFile($configFilename);
        $testDataDir = __DIR__ . "/Fixtures/$code";
        $kv = $importService->import($configData, $table, $testDataDir);
        return $kv;
    }

    #[Test]
    public function importCsvData(): void
    {
        $kv = $this->import('education');
        $kv->select('school');
        self::assertGreaterThan(1, $kv->count());
//        $kvService = static::getContainer()->get(PixieService::class);


        # csv

    }

    public function testMigration()
    {
        /** @var SqliteService $service */
        $service = static::getContainer()->get(SqliteService::class);
        [$tables, $diffs] = $service->playWithSqliteSchema(self::FILENAME);
        self::assertTrue(count($diffs) > 0);

    }

    public function tearDown(): void
    {
        parent::tearDown();
    }


}
