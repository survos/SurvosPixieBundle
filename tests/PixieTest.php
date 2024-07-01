<?php

namespace Survos\PixieBundle\Tests;


// this is in APP, waiting for https://github.com/symfony/symfony/discussions/57587 to move to bundle

use League\Csv\Exception;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\SqliteService;
use Survos\PixieBundle\StorageBox;
use Survos\PixieBundle\StorageBoxInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertDirectoryExists;

class PixieTest extends KernelTestCase
{
    const FILENAME='test.pixie.db';
    const MOVIE_TABLE_NAME='movie';

    #[Test]
    #[TestWith([0, 0, 0])]
    #[TestWith([2, 1, 1])]
    #[TestWith(['expected'=>3, 'a' => 1, 'c' => 2])]
    public function arrayTest(int $expected, int $a, int $b=0, int $c=0): void
    {
        $this->assertSame($expected, $a + $b + $c);
    }

    #[Test]
    #[TestWith(['education', 'config/packages/pixie/education.yaml', 'data/education', 'pixie/education.pixie.db'])]
    public function configPath(string $code, ?string $config=null, string $dataDir=null, ?string $db=null): void
    {
        /** @var PixieService $pixieService */
        $pixieService = static::getContainer()->get(PixieService::class);

        $filename = $pixieService->getConfigFilename($code);
        self::assertSame($config, $this->removeProjectDir($filename));
        self::assertFileExists($filename);

        $sourceFilesDir = $pixieService->getSourceFilesDir($code);
        self::assertSame($dataDir, $this->removeProjectDir($sourceFilesDir));
        assertDirectoryExists($sourceFilesDir);

        $filename = $pixieService->getPixieFilename($code);
        self::assertSame($db, $this->removeProjectDir($filename));
//        self::assertFileExists($filename);
    }

    #[Test]
    #[TestWith(['education', 4])]
    public function import(string $code, int $tableCount): void
    {
        /** @var PixieService $pixieService */
        $pixieService = static::getContainer()->get(PixieService::class);
        /** @var PixieImportService $importService */
        $importService = static::getContainer()->get(PixieImportService::class);
        $kv = $importService->import($code);
        assertCount($tableCount, $kv->getTables());

    }


    #[Test]
    public function configSettings(): void
    {
        $config = Yaml::parseFile(__DIR__.'/config/survos_pixie.yaml')['survos_pixie'];

        /** @var PixieService $pixieService */
        $pixieService = static::getContainer()->get(PixieService::class);

        self::assertSame($config['config_dir'], $this->removeProjectDir($pixieService->getConfigDir()));
        self::assertSame($config['data_root'], $this->removeProjectDir($pixieService->getDataRoot()));
        self::assertSame($config['db_dir'], $this->removeProjectDir($pixieService->getPixieDbDir()));


    }

    private function removeProjectDir(string $s): string
    {
        /** @var PixieService $pixieService */
        $pixieService = static::getContainer()->get(PixieService::class);
        return $pixieService->removeProjectDir($s);
    }

//    #[Test]
    #[TestWith(['/film.pixie.db', 'film'])]
    public function dbPath(string $expected, ?string $code=null): void
    {
        /** @var PixieService $kvService */
        $kvService = static::getContainer()->get(PixieService::class);
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $kv = $kvService->getStorageBox(self::FILENAME, [
//            self::MOVIE_TABLE_NAME => 'imdb_id|int,name'
        ]);

        $filename = $kvService->getPixieFilename($code);
        self::assertSame($expected, $filename);

        // remove the projectDir for the test?


        dump($projectDir);
        self::assertSame($expected, $projectDir);
        return;
        $kv = $kvService->getStorageBox(self::FILENAME, [
//            self::MOVIE_TABLE_NAME => 'imdb_id|int,name'
        ]);
    }

    #[Test]
    public function createTables(): void
    {
        $kernel = self::bootKernel([
            'environment' => 'test',
        ]);
        $this->assertSame('test', $kernel->getEnvironment());
        $movieTableName = self::MOVIE_TABLE_NAME;

        // $routerService = static::getContainer()->get('router');
        /** @var PixieService $kvService */
         $kvService = static::getContainer()->get(PixieService::class);

         $filename = $kvService->getPixieFilename('imdb');
        $kvService->destroy($filename);


         $kv = $kvService->getStorageBox($filename, [
             self::MOVIE_TABLE_NAME => 'imdb_id|int,name'
         ]);
        $this->assertCount(1, $kv->getTables(), "bad table count in $filename " . join("\n", $kv->getTables()))  ;
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

//    #[Test]
//    public function importJsonData(): void
//    {
////        $kvService = static::getContainer()->get(PixieService::class);
//
//        /** @var PixieImportService $importService */
//        $importService = static::getContainer()->get(PixieImportService::class);
//
//
//        $momaTable = 'moma.pixie.db';
//
//        $configFilename = __DIR__ . '/Fixtures/config/test-moma.yaml';
//        $this->assertTrue(file_exists($configFilename), $configFilename);
//        $configData = Yaml::parseFile($configFilename);
//        $testDataDir = __DIR__ . '/Fixtures/testdata';
//        $importService->import(new Config($configFilename), $momaTable, $testDataDir);
//    }

//    private function import(string $code): StorageBox
//    {
//        /** @var PixieImportService $importService */
//        $importService = static::getContainer()->get(PixieImportService::class);
//        $table = "$code.pixie.db";
//
//        $configFilename = __DIR__ . "/Fixtures/config/$code.yaml";
//        $this->assertTrue(file_exists($configFilename), $configFilename);
//        $configData = Yaml::parseFile($configFilename);
//        $testDataDir = __DIR__ . "/Fixtures/$code";
//        $kv = $importService->import($configData, $table, $testDataDir);
//        return $kv;
//    }

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
