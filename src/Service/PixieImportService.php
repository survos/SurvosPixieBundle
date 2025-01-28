<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use App\Event\FetchTranslationEvent;
use App\Event\FetchTranslationObjectEvent;
use App\Metadata\ITableAndKeys;
use App\Service\SourceService;
use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\LibreTranslateBundle\Service\TranslationClientService;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Table;
use Survos\PixieBundle\Model\Translation;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function Symfony\Component\String\u;
use \JsonMachine\Items;


class PixieImportService
{
    public function __construct(
        private readonly PixieService                      $pixieService,
        private readonly LoggerInterface                   $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        public bool                               $purgeBeforeImport = false,
        private array                             $listsByLabel = []

    )
    {
    }

    public function import(string      $pixieCode,
                           ?string     $subCode,
                           ?Config     $config = null,
                           int         $limit = 0,
                           int         $startingAt = 0,
                           bool        $overwrite = false, // individual records
                           array       $context = [], // for passing extra context, like addTranslationstrings
                           ?StorageBox $kv = null, // if we already created it.
                           ?string     $pattern = null,
                           ?callable   $callback = null): StorageBox
    {

        if (!$config) {
            $config = $this->pixieService->getConfig($pixieCode);
        }

        // the json files, slightly processed in :prepare, no csv
        $dirOrFilename = $this->pixieService->getSourceFilesDir($pixieCode, subCode: $subCode) . "/json";

        assert(file_exists($dirOrFilename), $dirOrFilename);
        $finder = new Finder();
        $files = $finder->in($dirOrFilename);
        if ($include = $config->getSource()->include) {
//            $files->name($include); // this filters out dir!
        }

        if ($ignore = $config->getIgnored()) {
            $files->notName($ignore);
        }
        $files->depth("<3");
        assert($files->count(), "No files (ignoring " . implode(',', $ignore) . ") in {$this->pixieService->getDataRoot()} $dirOrFilename");

        $fileMap = [];
        foreach ($files as $splFile) {
            if ($splFile->isDir()) {
                // add a prefix?  Or just ignore?
                continue;
            }
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
            $kv = $this->createKv($fileMap, $config, $pixieCode, $subCode);
        }
        $iKv = $this->eventDispatcher->dispatch(
            new StorageBoxEvent($pixieCode,
                mode: ITableAndKeys::PIXIE_IMAGE)
        )->getStorageBox();

        /* maybe hold off on related tables right now?
        $tKv = $this->eventDispatcher->dispatch(
            new StorageBoxEvent($pixieCode,
            mode: PixieInterface::PIXIE_TRANSLATION)
        )->getStorageBox();
        $tKv->select(PixieTranslationService::SOURCE); // we don't do anything with translations during import
        */

        assert(count($kv->getTables()), "no tables in $pixieCode");
        $validTableNames = $config->getTables();
        // so that they're ordered as they are in the config, and coll and loc are loaded before obj

        // won't work when multiple files going to the same table, e.g. ahd
//        $filesByTablename = array_flip($fileMap);
        foreach ($fileMap as $realPath => $tableName) {
            $filesByTablename[$tableName][] = $realPath;
//            array_flip($fileMap);

        }
//        dd($filesByTablename, $fileMap);

//        dd($fileMap, $config->getFiles(), $config, $filesByTablename);
//        foreach ($kv->getFiles())
        // $fn is the csv filename
        foreach (array_values($config->getFiles()) as $tableName) {
            if ($pattern && !str_contains((string) $tableName, $pattern)) {
                continue;
            }
//            AppService::assertKeyExists($tableName, $filesByTablename, "Missing table $tableName look for filename, not table");
            $filenames = $filesByTablename[$tableName];
            foreach ($filenames as $fn) {


//        foreach ($fileMap as $fn => $tableName) {
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
                $this->eventDispatcher->dispatch(new ImportFileEvent($fn, $kv->getFilename()));

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
                assert($pkName, "$tableName does not have a pk");
                assert($pkName == $kv->getPrimaryKey($tableName),
                    "$pixieCode / $tableName: " . $pkName . "<>" . $kv->getPrimaryKey($tableName));
                assert($table instanceof Table, "Invalid table type");
                $rules = $config->getTableRules($tableName);
                $kv->map($rules, [$tableName]);
                $kv->select($tableName);

                [$ext, $iterator, $headers] =
                    $this->setupHeader($config, $tableName, $kv, $fn);
                assert(count($kv->getTables()), "no tables in $pixieCode");

                // takes a function that will iterate through an object
//            $kv->addFormatter(function());

                if (isset($headers)) {
                    if (count($headers) !== count(array_unique($headers))) {
//                        dd($headers, array_unique($headers));
//                        asort($headers);
//                        dd($headers);
                    }
                }
//                    assert(),
//                        "look for duplicate key!\n" .
//                        json_encode($headers, JSON_PRETTY_PRINT));
                // don't parse the header match each time, store them
//            $regexRules = $configData['tables'][$tableName]['formatter'] ?? [];
                // why not mapped headers?
                // setup the dataRules to apply later
                $dataRules = [];
                foreach ($headers as $header => $origHeader) {
                    foreach ($table->getPatches() as $headerRegex => $regexRules) {
                        if (preg_match($headerRegex, (string) $header, $mm)) {
                            $dataRules[$header] ??= [];
                            $dataRules[$header] += $regexRules;
                        }
                    }
                }
                $iKv->beginTransaction();
                $kv->beginTransaction(); // so that events that populate related tables are persisted.  Meh.
                $event = $this->eventDispatcher->dispatch(new RowEvent(
                    $config->code, $tableName,
                    null,
                    config: $config,
                    action: self::class,
                    type: RowEvent::PRE_LOAD,
                    storageBox: $kv,
                    imageStorageBox: $iKv,
                    context: $context));

                $pk = $kv->getPrimaryKey($tableName);
                // this is the json/csv iterator
                foreach ($iterator as $idx => $row) {
                    if ($startingAt && ($idx < $startingAt)) {
                        continue;
                    }

                    // if it's json, remap the keys.
                    $reverse = array_flip($headers);
                    // mapped Header stuff has moved to pixie:prepare
//                    if (false)
                        if ($ext === 'json') {
                            $mappedRow = [];
                            // if new values after first row
                            $remainingHeaders = array_keys((array)$row);
                            foreach ($headers as $header => $headerOrig) {
                                $mappedRow[$header] = $row->{$headerOrig} ?? null;
                            }


                            // handle keys that aren't in the mapped header, which is too dependent on the first row data
                            $unhandledKeys = array_diff($x = array_keys((array)$row), $y = array_keys($mappedRow));
                            foreach ($unhandledKeys as $newKey) {
                                if (!array_key_exists($newKey, $reverse)) {
//                                    assert(false, "new key $newKey missing in $tableName \n" .
//                                        json_encode($row, JSON_PRETTY_PRINT) . "\n\n" .
//                                        json_encode($reverse, JSON_PRETTY_PRINT) . "
//                                    add $newKey to required fields so it exists in the first row"
//                                    );
                                    $mappedRow[$newKey] = $row->{$newKey} ?? null;
                                } else {
                                }
                            }
                            // if there are new headers, add them
//                        dd(row: $row, mappedRow: $mappedRow, );
//                            $row = $mappedRow;
                            $row = (object)$mappedRow;
                        }
                    assert($row);

                    // just check the first row
                    if ($idx == 0) {
                        assert(property_exists($row, $pk),
                            $tableName . " should have primaryKey `$pk`  " .
                            json_encode($row, JSON_PRETTY_PRINT));
                    }

//                    $row = $event->row;

                    if (!$row->{$pkName} ?? null) {
                        // e.g. empty excel rows.  Could handle in the grid:excel-to-csv
                        $this->logger->error("Empty pk, skipping row " . $idx);
//                        dd($row, $pkName, $idx);
                        continue;
                    }
                    SurvosUtils::assertKeyExists($pkName, $row, "in $fn");
                    $id = $row->{$pkName} ?? null;
                    assert($id, "no primary key in $tableName row " . json_encode($row, JSON_PRETTY_PRINT));
                    $exists = $kv->has($id, preloadKeys: true);
                    $row = $this->applyDataRules($row, $dataRules);
                    if (!$row) {
                        dd($row, $idx, $tableName);
                        continue;
                    }
                    $event = $this->eventDispatcher->dispatch(new RowEvent(
                        $config->code,
                        $tableName,
                        key: $row[$pk] ?? null,
                        row: $row,
                        index: $idx,
                        action: self::class,
                        storageBox: $kv,
                        imageStorageBox: $iKv,
                        context: $context));
                    $row = $event->row;
                    // handling relations could be its own RowEvent too, for now it's here
//                dd($row);
                    $row = $this->handleRelations($kv, $config, $pixieCode, $table, $row);
//                    dd(afterRelations: $row);
                    $event->row = $row;
//                $tableName=='obj' && dd($row['classification'], $event->row['classification']);

//                $tableName == 'obj' && dd($row);

                    if ($callback) {
                        // for batching
                        if (!$continue = $callback($row, $idx, $kv)) {
                            break;
                        }
                    }

                    if (!$overwrite && $exists) {
                        continue;
                    }

                    // seems hackish, better to use discard
                    if (!$event->row) {
                        dd($event);
                        continue;
                    }
                    // don't set if discard
                    if ($event->type == RowEvent::DISCARD) {
                        continue;
                    }

                    // add the source strings to the translation table


//                $event = new FetchTranslationObjectEvent($row, )
//                    $sourceString = $row[$tKey];
                    if (count($table->getTranslatable())) {

                        $sourceLocale = $config->getSource()->locale ?? $row['locale'] ?? null;
                        if (!$sourceLocale) {
                            dd($row);
                        }

//                        dump(row: $row, transFields: $table->getTranslatable());

                        if (class_exists(FetchTranslationObjectEvent::class)) {
                            // for source table, Not libre, which happens during pixie:trans --queue
                            $event = $this->eventDispatcher->dispatch(
                                new FetchTranslationObjectEvent(
                                    $row, // or $item?
                                    pixieCode: $pixieCode,
                                    sourceLanguage: $sourceLocale,
                                    targetLanguage: $sourceLocale,
                                    table: $tableName, // for debugging,
                                    key: $row[$table->getPkName()],
                                    keys: $table->getTranslatable()
                                ));

//                            dd($event->translationModels, $tKv->getSelectedTable());
                            // this populates the source table of the translation database,
//                            dump($row);
                            $row = $event->getNormalizedData();
//                            dump($row);
                            foreach ($event->translationModels as $transModel) {
                                if (!$kv->has($transModel->getHash(), PixieInterface::PIXIE_STRING_TABLE)) {
                                    $kv->set($transModel->toArray(), PixieInterface::PIXIE_STRING_TABLE);
                                }
                            }
                        }
                    }

                    assert($row['license'] ?? '' <> 'Copyrighted', "invalid license");
                    try {
                        $kv->set($row, $tableName);
                    } catch (\Exception $e) {
                        dd($kv->getFilename(), $kv, $e, $kv->getSelectedTable(), row: $row);
                    }

                    if ($limit && ($idx >= $limit - 1)) break;
//            dd($kv->get($row['id']));
                    // dd($row); break;
                }
                $kv->commit();
                $count = $kv->count();
                $this->logger->info($kv->getFilename() . '/' . $kv->getSelectedTable() . " now has " . $count);
//                if ($tKv->inTransaction()) {
//                    $tKv->commit();
//                }
            }
        }
        if ($kv->inTransaction()) {
            $kv->commit();
        }

        $event = $this->eventDispatcher->dispatch(new RowEvent(
            $config->code, $tableName, null,
            imageStorageBox: $iKv,
            action: self::class,
            type: RowEvent::POST_LOAD,
            storageBox: $kv));

        if ($iKv->inTransaction()) {
            $iKv->commit();
        }

        return $kv;
//        dd($fileMap);

    }

    public function createKv(array   $fileMap,
                             Config  $config,
                             string  $pixieCode,
                             ?string $subCode,
                             bool    $destroyFirst = false
    ): StorageBox
    {
//        assert(($pixieCode<>'md') || $subCode, "$pixieCode $subCode");
        // only create the tables that match the filenames
        $tablesToCreate = [];
        foreach ($fileMap as $fn => $tableName) {
            $tables = $config->getTables();
            foreach ($tables as $tableName => $tableData) {
                $tablesToCreate[$tableName] = $tableData;
            }
        }
        $pixieFilename = $this->pixieService->getPixieFilename($pixieCode, $subCode);
        if ($destroyFirst) {
            $this->pixieService->destroy($pixieFilename);
        }

        $kv = $this->pixieService->getStorageBox($pixieCode, subCode: $subCode,
            createFromConfig: true,
        );
//        if (str_contains($kv->getFilename(), 'edu')) dd($kv->getFilename());
        return $kv;
        return [$splFile, $tableName, $mm, $fileMap, $fn, $tables, $tableData, $kv];
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
        if ($ext === 'json') {
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
        $table = $config->getTable($tableName);
        $mappedHeader = $kv->mapHeader($headers,
            $config->getSource()->propertyCodeRule,
            regexRules: $rules);
//        ($tableName == 'obj') && dd($tableName, $mappedHeader);
        // keep for replacing the key names later
//                dd($headers, mapped: $mappedHeader, rules: $rules);
        $this->eventDispatcher->dispatch(
            $headerEvent = new CsvHeaderEvent($mappedHeader, $fn)
        );
        $headers = $headerEvent->header;
//
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
        } else {
            // if it's json, we might be missing headers, so add them if it's in the table
            foreach ($table->getProperties() as $property) {
                if (!array_key_exists($propertyCode = $property->getCode(), $headers)) {
                    $headers[$propertyCode] = $propertyCode;
                }
            }
        }
//        dd($headers, $ext);

        return [$ext, $iterator, $headers];
    }

    /**
     *
     * In many cases, the data in one table is really a reference to data in another tables, often via a label, e.g.
     *     material: bronze, color: [red, blue], collection: ROM, lang: de, loc:001-02
     *
     * Sometimes a related list exists in the database, like collections or even people.  If not, we need to populate the related list with the label.
     *
     *
     *
     * @param StorageBox|null $kv
     * @param string $pixieCode
     * @param Table $table
     * @return array $row modified row, side effect of creating lists.
     */
    public function handleRelations(?StorageBox $kv,
                                    Config      $config,
                                    string      $pixieCode,
                                    Table       $table,
                                    array       $row): array
    {
        assert(count($kv->getTables()), "no tables in $pixieCode");
        foreach ($table->getProperties() as $property) {

            $propertyCode = $property->getCode();
            $settings = $property->getSettings();

            // only lists and relations
            if (!$relatedTableName = $property->getSubType()) {
                continue;
            }

            if (!$label = $row[$propertyCode] ?? null) {
                continue; // skip if blank
            }

            // list and relation are merging...
            if ($property->isRelation()) {
                $relatedTable = $config->getTable($relatedTableName);
                if ($delim = $property->getDelim()) {
                    $values = array_map('trim', explode($delim, (string) $row[$propertyCode]));
                    if ($property->getValueType() == '@pk') {
                        // @todo: make a many-to-many table.  For now, a simple array
                        $row[$propertyCode] = $values;
                    } elseif ($property->getValueType() == '@label') {
                        // create a new table.
                        dd($property, $settings, $row[$property->getCode()], $values);
                    }
                }
            }

            if ($relatedTableName = $property->getListTableName()) {
                $relatedTable = $config->getTable($relatedTableName);
                $pkName = $relatedTable->getPkName();
                $valueType = $property->getValueType();
//                ($propertyCode == 'classification') && dd($property, $relatedTable, $relatedTableName, $this->listsByLabel);
                // first time, cache
                if (!array_key_exists($relatedTableName, $this->listsByLabel)) {
                    $this->listsByLabel[$relatedTableName] = [];
                    // eh, don't we have this as a pixie table?
                    foreach ($kv->iterate($relatedTableName) as $relatedRow) {
                        $this->listsByLabel[$relatedTableName][$relatedRow->label()] =
                            $relatedRow->getKey();
                    }
                }
                $isMultiple = is_array($label); // @todo: define in @list or @rel
                if ($isMultiple) {
                    $labels = $label;
                    $row[$propertyCode] = []; // reset the existing text labels
                } else {
                    $labels = [$label];
                }
                foreach ($labels as $label) {
                    if (!$label) {
                        continue;
                    }
                    // if label is missing, create it in the relatedTable pixie
                    assert(is_string($relatedTableName), json_encode($relatedTableName));
//                    $relatedTableName=='tag' && dd($label, $labels, $this->listsByLabel[$relatedTableName]);

                    assert(is_string($label), "$relatedTableName " . json_encode($label));

                    SurvosUtils::assertKeyExists($relatedTableName, $this->listsByLabel);
//                    SurvosUtils::assertKeyExists($label, $this->listsByLabel[$relatedTableName], "missing label in listsByLabel $relatedTableName");
                    if (!array_key_exists($label, $this->listsByLabel[$relatedTableName])) {
                        if ($valueType === '@code') {
                            //
                        } elseif (in_array($valueType, ['@label', '@labels'])) {
//                                dd($relatedRow);
                                if (!$sourceLang = $row['locale'] ?? null) {
                                    if (!$sourceLang = $config->getSource()->locale) {
                                        assert(false, "unable to get source language");
                                        dd($row);
                                    }
                                }
                            $relatedId =  TranslationClientService::calcHash($label, $sourceLang);
                            $relatedRow = [
                                'label' => trim($label),
//                                    '_locale' => $sourceLang, // should every row have a locale?
                            ];
                                $_t[$sourceLang][PixieInterface::TRANSLATION_LABEL]=$relatedRow['label'];
                                // this is to get the orig strings.  No database lookup
//                            dump($relatedRow);
                            // it seems like a lot of work to get the source hash, but it also modifies the $row and adds hash.  I think.
                            // candidate for review
                                if (class_exists(FetchTranslationObjectEvent::class)) {
                                    $event = $this->eventDispatcher->dispatch(
                                        new FetchTranslationObjectEvent($relatedRow,
                                            $sourceLang,
                                            $sourceLang,
                                            storageBox: $kv,
                                            pixieCode: $pixieCode,
                                            table: $relatedTableName,
                                            key: $relatedId,
                                            keys: [PixieInterface::TRANSLATION_LABEL]
                                        )
                                    );

                                    // add to the pixie string table to translate later.  Don't overwrite
                                    /** @var Translation $translationModel */
                                    foreach ($event->translationModels as $translationModel) {
                                        assert($translationModel->isSource(), "translations have been moved.");
//                                        dd($translationModel->toArray(), $translationModel->getHash());
                                        // same as in handleRelations, need to refactor.
                                        if (!$kv->has($translationModel->getHash(), table: PixieInterface::PIXIE_STRING_TABLE, preloadKeys: true)) {
                                            $kv->set($translationModel->toArray(), PixieInterface::PIXIE_STRING_TABLE);
                                        }
                                    }

                                    // the label and _translations have been set
                                    $relatedRow = $event->getNormalizedData();
//                                    dd($relatedRow);
                                    // set the related row field to the hash, not the text
                                    $relatedId = $relatedRow[PixieInterface::TRANSLATION_LABEL]; //
//                                    dd($relatedId, $relatedRow, $relatedTableName, $_t);
                                    // replace the key with the translation key
                                    $relatedRow[$pkName] = $relatedId;
//                                dd(related: $relatedRow);
                                }
                                // populate the related table
                                $relatedRow[PixieInterface::TRANSLATED_STRINGS] = $_t;
                                $kv->set($relatedRow, $relatedTableName);
//                                $kv->set($relatedRow, $relatedTableName, properties: [
//                                    '_t' => $_t,
//                                ]);
//                                $kv->commit();
//                                $newItem = $kv->get($relatedId, $relatedTableName);
////                                dd($pkName, $relatedRow, $newItem, $newItem->getData());
//                                $kv->beginTransaction();
                                $this->listsByLabel[$relatedTableName][$label] = $relatedId;
                        } else {
                            assert(false, "valueType not handled " . $propertyCode . ' ' . $valueType);
                        }
                    }
//                    AppService::assertKeyExists($propertyCode, $row, "missing in table " . $table->getName());

                    $relatedId = $this->listsByLabel[$relatedTableName][$label];
                    if ($isMultiple) {
//                        dd($row, $propertyCode);
                        $row[$propertyCode][] = $relatedId; // if this is a string, this could instead be the translation source key?
//                        dd($row[$propertyCode]);
                    } else {
                        $row[$propertyCode] = $relatedId; // if this is a string, this could instead be the translation source key?
                    }
                }

                // fetch the key by label?  Or keep it in memory?
                // create the relation.  unlike the old system, we can have relations in the sql
            }

        }
        return $row;
    }

    /**
     * @param mixed $row
     * @param array $dataRules
     * @param array $mm
     * @return array
     */
    public function applyDataRules(mixed $row, array $dataRules): array
    {
        // eh, this isn't lovely.
        if (is_object($row)) {
            $row = (array)$row;
        }
        foreach ($row as $k => $v) {
            foreach ($dataRules[$k] ?? [] as $dataRegexRule => $substitution) {
                $match = preg_match($dataRegexRule, (string) $v, $mm);
                if ($match) {
                    if ($substitution === '') {
                        $row[$k] = null;
                    } else {
                        // or a preg_replace?
                        $row[$k] = str_replace($mm[0], $substitution, $row[$k]);
//                                dd($row[$k]);
                    }
                }
            }
        }
        return $row;
    }

}
