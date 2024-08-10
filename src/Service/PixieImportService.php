<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function Symfony\Component\String\u;
use \JsonMachine\Items;


class PixieImportService
{
    public function __construct(
        private PixieService                      $pixieService,
        private LoggerInterface                   $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        public bool $purgeBeforeImport = false
    )
    {
    }

    public function import(string $pixieCode,
                           ?Config $config=null,
                           int    $limit = 0,
                           bool $overwrite = false, // individual records
                           ?StorageBox $kv=null, // if we already created it.
                           ?callable $callback=null): StorageBox
    {

        if (!$config) {
            $config = $this->pixieService->getConfig($pixieCode);
        }
        // the csv/json files
        $dirOrFilename = $this->pixieService->getSourceFilesDir($pixieCode);

        assert(file_exists($dirOrFilename), $dirOrFilename);
        $finder = new Finder();
        $files = $finder->in($dirOrFilename);
        if ($include = $config->getSource()->include) {
            $files->name($include);
        }

        if ($ignore = $config->getIgnored()) {
            $files->notName($ignore);
        }
        assert($files->count(), "No files in {$this->pixieService->getDataRoot()} $dirOrFilename");

        foreach ($files as $splFile) {
            $this->eventDispatcher->dispatch(new ImportFileEvent($splFile->getRealPath()));
//            assert($splFile->getExtension() <> 'csv', json_encode($ignore));
            $map[$splFile->getRealPath()] = u($splFile->getFilenameWithoutExtension())->snake()->toString();
                foreach ($config->getFileToTableMap() as $rule => $tableNameRule) {
                if (preg_match($rule, $splFile->getFilename(), $mm)) {
//                    dd($mm, $splFile->getFilename(), $tableName);
                    $map[$splFile->getRealPath()] = $tableNameRule;
                    break;
                }
            }
            $fileMap[$splFile->getRealPath()] = $map[$splFile->getRealPath()] ?? null;
        }
        unset($splFile);

        assert($config);
//        list($splFile, $tableName, $mm, $fileMap, $fn, $tables, $tableData, $kv) =
        if (!$kv) {
            $kv = $this->createKv($fileMap, $config, $pixieCode);
        }
        assert(count($kv->getTables()), "no tables in $pixieCode");
        $validTableNames = $config->getTables();

        // $fn is the csv filename
        foreach ($fileMap as $fn => $tableName) {
            if (empty($tableName)) {
                $this->logger && $this->logger->warning("Skipping $fn, no map to tables");
                dd($fn, $tableName);
                continue;
            }
//            dd($fn, $tableName, $fileMap);
//            $schemaTables = $kv->inspectSchema();
            if (!array_key_exists($tableName, $validTableNames)) {
                $this->logger && $this->logger->warning("Skipping $fn, table is undefined");
//                dd($tableName, $kv->getFilename(), $validTableNames);
                continue;
            }
            // we could do a callback here tagging it as a file.  Or some sort of event?
            $this->logger && $this->logger->warning("Importing $fn to $tableName");
//            if (!str_contains($pixieDbName, 'moma')) dd($tableName, $pixieDbName, $kv->getFilename());
//            dd($tableName, $tablesToCreate);
//            $table = [$tableName]??null;

//            if (!$table) {
////                throw new \LogicException("$tableName is not defined in tables ");
//                $this->logger && $this->logger->warning("Skipping $tableName, not defined in tables");
//                continue;
//            }
//            $tableData = (array)$table; // $tables[$tableName];
            $tables = $config->getTables(); // with the rules and such
            $table = $tables[$tableName];
            $pkName = $table->getPkName();
            assert($pkName == $kv->getPrimaryKey($tableName), $tableName . ": " . $pkName . "<>" . $kv->getPrimaryKey($tableName));
            assert($table instanceof Table, "Invalid table type");
            $rules = $config->getTableRules($tableName);
            $kv->map($rules, [$tableName]);
            $kv->select($tableName);

            list($ext, $iterator, $headers) =
                $this->setupHeader($config, $tableName, $kv, $fn);
            assert(count($kv->getTables()), "no tables in $pixieCode");
//            dd($headers, $tableName, $config);

            // takes a function that will iterate through an object
//            $kv->addFormatter(function());

            $kv->beginTransaction();
            if (isset($headers))
                assert(count($headers) == count(array_unique($headers)), json_encode($headers));
            // don't parse the header match each time, store them
            $regexRules = $configData['tables'][$tableName]['formatter'] ?? [];
            // why not mapped headers?
            foreach ($headers as $headerOrig=>$header) {
                foreach ($regexRules as $variableRegexRule => $dataRegexRules) {
                    if (preg_match($variableRegexRule, $header)) {
                        $dataRules[$header] = $dataRegexRules;
                    }
                }
            }

            $event = $this->eventDispatcher->dispatch(new RowEvent(
                $config->code, $tableName,
                null,
                action: self::class,
                type: RowEvent::PRE_LOAD,
                storageBox: $kv ));

            // this is the json/csv iterator
            foreach ($iterator as $idx => $row) {
                // if it's json, remap the keys
                if ($ext === 'json') {
                    $origRow = $row; // for debugging
                    $mappedRow = [];
                    foreach ($headers as $header=>$headerOrig) {
                        $mappedRow[$header] = $row->{$headerOrig}??null;
                    }
//                    dd($row, $mappedRow);
                    $row = $mappedRow;


//                    $resolver->setDefault($header, $row[])
//                    $values = array_values((array)$row);
//                        dd($idx, $headers, $row, $origRow, $rules);
//                        // hack, will burn us if fields are in a different order.  need a better solution
//                        if ($headerCount < $valueCount) {
//                            $values = array_splice($values, 0, $headerCount);
//                        } else {
//                            $values = array_pad($values, $headerCount, null);
//                        }
//                        if ( ($headerCount = count($headers)) <> ($valueCount = count($values))) {
//                            dd($headers, $values);
//                        }
//                    if ( ($headerCount = count($headers)) <> ($valueCount = count($values))) {
//                    }
//                    $row = array_combine($headers, $values);
//                    dump(table: $tableName, orig: $origRow, mapped: $mappedHeader, new_row: $row);
//                    dd($idx, $row, $headers); return $kv;
                }
//                dump($ext, $mappedHeader, $row);
                $pk = $kv->getPrimaryKey($tableName);
                assert(array_key_exists($pk, $row),
                    $tableName . " should have $pk  " .
                    json_encode($row, JSON_PRETTY_PRINT));

                $existing = null;
                $exists = $kv->has($row[$pkName], preloadKeys: true);
//                if ($exists) {
//                    $existing = $kv->get($row[$pkName]);
//                }
                if (!$overwrite && $exists) {
                    if ($row[$pk] === 114) {
//                        dd($row, $kv->has($row[$pkName]), ));
                    }
                    continue;
                }

                foreach ($row as $k => $v) {
                    foreach ($dataRules[$k] ?? [] as $dataRegexRule => $substitution) {
                        $match = preg_match($dataRegexRule, $v, $mm);
                        if ($match) {
                            // @todo: a preg_replace?
                            $row[$k] = $substitution === '' ? null : $substitution;
                        }
                    }
                }
                assert(count($kv->getTables()), "no tables in $pixieCode");

                if (!$row) {
                    dd($row, $idx, $tableName);
                    continue;
                }

                $event = $this->eventDispatcher->dispatch(new RowEvent(
                    $config->code, $tableName, $row,
                    action: self::class,
                    storageBox: $kv ));
                // seems hackish, better to use discard
                if (!$event->row) {
                    dd($event);
                    continue;
                }
                $row  = $event->row;
                // don't set if discard
                if ($event->type == RowEvent::DISCARD) {
                    continue;
                }

                try {
                    $kv->set($row);
                } catch (\Exception $e) {
                    dd($kv->getFilename(), $kv, $e);
                }
//                if ($idx == 1) dump($tableName, $row, $limit, $idx);
                if ($limit && ($idx >= $limit-1)) break;
                if ($callback) {
                    if (!$continue = $callback($row, $idx, $kv)) {
                        dd('stopping!');
                        break;
                    }
                }
//            dd($kv->get($row['id']));
                // dd($row); break;
            }
            $kv->commit();
//            $count = $kv->count();
        }
        $event = $this->eventDispatcher->dispatch(new RowEvent(
            $config->code, $tableName, null,
            action: self::class,
            type: RowEvent::POST_LOAD,
            storageBox: $kv ));

        return $kv;
//        dd($fileMap);

    }

    public function createKv(array $fileMap,
                             Config $config,
                             string $pixieCode,
    bool $destroyFirst = false
    ): StorageBox
    {


        // only create the tables that match the filenames
        $tablesToCreate=[];
        foreach ($fileMap as $fn => $tableName) {
            $tables = $config->getTables();
            foreach ($tables as $tableName => $tableData) {
                $tablesToCreate[$tableName] = $tableData;
            }
        }
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode);
        if ($destroyFirst) {
            $this->pixieService->destroy($pixieFilename);
        }

        $kv = $this->pixieService->getStorageBox($pixieCode,
            createFromConfig: true,
        );
//        if (str_contains($kv->getFilename(), 'edu')) dd($kv->getFilename());
        return $kv;
        return array($splFile, $tableName, $mm, $fileMap, $fn, $tables, $tableData, $kv);
//        dd($fileMap, $tablesToCreate);
    }

    /**
     * @param mixed $splFile
     * @param array $configData
     * @param mixed $tableName
     * @param StorageBox $kv
     * @param int|string $fn
     * @return array
     * @throws \JsonMachine\Exception\InvalidArgumentException
     * @throws \JsonMachine\Exception\PathNotFoundException
     * @throws \League\Csv\Exception
     * @throws \League\Csv\InvalidArgument
     * @throws \League\Csv\SyntaxError
     * @throws \League\Csv\UnavailableStream
     */
    public function setupHeader(Config $config, string $tableName, StorageBox $kv, int|string $fn): array
    {
        $ext = pathinfo($fn, PATHINFO_EXTENSION);
        if ($ext == 'json') {
            $iterator = Items::fromFile($fn)->getIterator();
            $firstRow = $iterator->current();
            // @todo: handle nested properties
            $headers = array_keys(get_object_vars($firstRow));
            $iterator->rewind();
        } else { // if (in_array($ext, ['tsv', 'csv', 'txt'])) {
            $csvReader = Reader::createFromPath($fn, 'r');
            $result = Info::getDelimiterStats($csvReader, ["\t", ','], 3);
            // pick the highest one
            arsort($result);
            $csvReader->setDelimiter(array_key_first($result));
            $csvReader->setHeaderOffset(0); //set the CSV header offset

            $headers = $csvReader->getHeader();
//                assert(array_key_exists($tableName, $configData), json_encode($configData));
//                dd($originalHeaders, $headers);
        }

        $rules = $config->getTableRules($tableName);
        $mappedHeader = $kv->mapHeader($headers, regexRules: $rules);
//            dd($rules, $configData, $tableName, filename: $splFile->getFilename(), mappedHeader: $mappedHeader);
        // keep for replacing the key names later
//                dd($headers, mapped: $mappedHeader, rules: $rules);
        $this->eventDispatcher->dispatch(
            $headerEvent = new CsvHeaderEvent($mappedHeader, $fn));
        $headers = $headerEvent->header;
//
//                dump($headerEvent->header);
        // headers is now a map from column headers to properties
        if (count($headers) != count(array_unique($headers))) {
            dd($headers, array_unique($headers));
        }

        if ($ext !== 'json') {
            try {
                $headerKeys = array_keys($headers);
                $iterator = $csvReader->getRecords($headerKeys);
            } catch (SyntaxError $error) {
                dd($headers, $error->getMessage());
            }
        }

        return [$ext, $iterator, $headers];
    }

}
