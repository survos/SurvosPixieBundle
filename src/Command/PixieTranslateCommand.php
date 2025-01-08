<?php

namespace Survos\PixieBundle\Command;

//use App\Entity\Instance;
//use App\Entity\Owner;
//use App\EventListener\TranslationEventListener;
//use App\Message\TranslationMessage;
//use App\Metadata\PixieInterface;
//use App\Repository\OwnerRepository;
//use App\Repository\ProjectRepository;
//use App\Service\AppService;
//use App\Service\LibreTranslateService;
//use App\Service\PdoCacheService;
//use App\Service\PennService;
//use App\Service\ProjectConfig\PennConfigService;
//use App\Service\ProjectService;
use Survos\PixieBundle\Service\LibreTranslateService;
use Survos\PixieBundle\Service\TranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\GridGroupBundle\Service\CsvDatabase;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Survos\Scraper\Service\ScraperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\ConfigureWithAttributes;
use Zenstruck\Console\IO;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;
use function PHPUnit\Framework\assertNull;

#[AsCommand('pixie:translate', 'Translate the existing fields to a separate ktv')]
final class PixieTranslateCommand extends InvokableServiceCommand
{

    use RunsCommands, RunsProcesses;

    // we are using the digmus translation dir since most projects are there.

    public function __construct(
        #[Autowire('%kernel.enabled_locales%')] private array $supportedLocales,
        private MessageBusInterface                           $bus,
        protected EntityManagerInterface                      $entityManager,
        private TranslationService                            $translationService,
        private PixieService                                  $pixieService,
        private LibreTranslateService                         $libreTranslateService,
        private TranslationEventListener                      $translationEventListener, // @todo: dispatch or inject just the method
        protected ParameterBagInterface                       $bag,
        private readonly NormalizerInterface                  $normalizer,
        private readonly EventDispatcherInterface             $eventDispatcher,
        private readonly LoggerInterface $logger,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        IO                                                                                             $io,
//        ProjectService                                                                                 $ps,
        EntityManagerInterface                                                                         $entityManager,
        LoggerInterface                                                                                $logger,
        ParameterBagInterface                                                                          $bag,
        PropertyAccessorInterface                                                                      $accessor,
//        OwnerRepository                                                                                $ownerRepository,
        PixieService                                                                                   $pixieService,
        #[Argument(description: 'config code')] ?string                                                $configCode,

        #[Option(description: 'translation engine')] string                                            $engine = 'libre',
        #[Option('table', description: 'filter by table')] ?string                                     $tableFilter = null,
        #[Option(description: 'filter by base table marking')] ?string                                 $marking = null,
        #[Option(description: 'target targetLocale')] ?string                                          $target = null,
        #[Option(description: 'message bus transport')] ?string                                        $transport = null,
        #[Option(description: 'limit')] int                                                            $limit = 0,
        #[Option(name: 'batch', description: 'batch size')] int                                        $batchSize = 100,
        #[Option(name: 'index', description: 'index after flush')] ?bool                               $indexAfterFlush = false,
        #[Option(name: 'populate', description: 'populate the source keys in tkv engine table')] ?bool $populateKeys = false,
        #[Option(name: 'translate', description: 'translate missing keys')] ?bool                      $translate = false,
        #[Autowire('%env(DEFAULT_TRANSPORT)%')] ?string $defaultTransport=null,

    ): int
    {
        $configCode ??= getenv('PIXIE_CODE');
        $transport ??= $defaultTransport;

        $tKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode, isTranslation: true))->getStorageBox();
//        $tKv = $this->translationService->getTranslationStorageBox($pixieCode);
//        dd($tKv->getFilename());
        $config = $this->pixieService->getConfig($configCode);

        if ($target==='') {
            $locales = []; // skip translation, just insert into source
        } else {
            $locales = $target ? explode('|', $target) : $this->supportedLocales;
        }
        // load the source translations into the 'source' table
        $translatableTables = $this->getTranslatableTables($config, $tableFilter);
        if ($populateKeys) {
            // find all the source keys where a target doesn't exist.
            $counts = $this->populateTranslationKeys($configCode, $marking, $translatableTables, $locales, $tKv);
            $io->info("keys populated");
//            dump($counts);
        }

        if ($translate) {
            // we could have a workflow, then dispatch, queue, etc.
            $tKv->select('source');

            // @todo: sql fragments so we aren't looping over everything!  in source. maybe.
            // insert into guests values ('bob', json('["apples", "oranges"]'));
            // select name from guests, json_each(likes) where json_each.value='oranges';
//        $where['json_each.translated <> :target'] = ['target' => $target];

            // get the source string map
            // could also populate redis or a doctrine kv hash

            // cache the source strings by key so we can dispatch with the original text.
            $sourceStrings = [];
            // https://medium.marco.zone/add-the-symfony-stopwatch-to-services-without-changing-them-e52266df0df1
            $stopwatch = new Stopwatch();
            $stopwatch->start('eventName');;
            foreach ($tKv->iterate('source') as $hash => $sourceItem) {
                $sourceStrings[$hash] = trim((string) $sourceItem->text());
            }
            $event = $stopwatch->stop('eventName');
            foreach ($event->getPeriods() as $period) {
                $msg = sprintf("%3.1fMBin %3.1f s", $period->getMemory() / (1024 * 1024),
                    $period->getDuration() / 1000);
                $io->warning(count($sourceStrings) . ' loaded from source ' . $msg);
            }

            $where = ['marking' => TranslationService::NOT_TRANSLATED];
            if ($target) {
                $where['target'] = $target;
            }

            $count = $tKv->count($engine = TranslationService::ENGINE, $where);
            $io->title("dispatching $count/$limit missing $engine messages to $target");

            $progressBar = new ProgressBar($io, $count);
            $rows = $tKv->iterate($engine, $where, max: $limit);
            foreach ($rows as $tItem) {
                $progressBar->advance();
                $targetLocale = $tItem->target();
                $text = $sourceStrings[$tItem->hash()] ?? '';
                $snippet = substr($text, 0, 30);
                $this->logger->info("queuing {$tItem->prop()} {$tItem->getKey()} $snippet to $targetLocale");
                assert($text, "empty text!");

                $stamps = [];
                if ($transport) {
                    $stamps[] = new TransportNamesStamp($transport);
                }
                $envelope = $this->bus->dispatch(
                    $msg = new TranslationMessage(
                        $configCode,
                        $tItem->getKey(),
                        $text, // text to be translated
                        $tItem->hash() // the source key
                    ), $stamps);

                $envelope = $this->bus->dispatch($msg);
                assert($envelope);
            }
            $progressBar->finish();
            $this->io()->success("Finished dispatching $count translations");
        }

        // last iteration -- merge all the existing translations

        // only tables with translations
        if ($indexAfterFlush) {
            $this->populateTranslations($configCode, $tKv, $translatableTables);
            $io->success("translations imported into $configCode");
            foreach ($translatableTables as $tableName) {
                $cli = "pixie:index $configCode --table $tableName --reset --batch=$batchSize";
                $this->io()->warning('bin/console ' . $cli);
                $this->runCommand($cli);
            }
        }
        return self::SUCCESS;

    }

    private function translateSourceItem(Item $sourceItem, string $locale, StorageBox $tKv): ?Item
    {
        if (!$text = $sourceItem->text()) {
            return null;
        }
        if (is_numeric($text)) {
            return null;
        }
        $tKv->select(TranslationService::ENGINE);
        $sourceHash = $sourceItem->getKey();
        // hack until we get sql fragments working
        $targetKey = TranslationService::cacheKey($sourceHash, $locale);
        if ($tKv->has($targetKey, preloadKeys: true)) { // }, where: ['locale' => $locale])) {
            dd($tKv->get($targetKey));
            return $tKv->get($targetKey);
        }

        if ($text) {
            $tKv->beginTransaction();
            // horrid hack
            if ($hack = preg_match('/Botella gollete asa (.*)/', $text, $m)) {
                $text = $m[1];
            }
            $response = $this->translationService->translateLine(
                $text,
                from: $sourceItem->source(),
                to: $locale,
                engine: TranslationService::ENGINE
            );
            if (empty($response)) {
                dump($sourceItem->getData(), $response, $sourceItem->locale(), $locale);
                return null;
            }
            if ($hack) {
                if ($locale == 'en') {
                    // in english only!
                    $response = 'Bottle neck handle ' . $response;
                }
            }
//            $tKv->set($response);
            $maxLen = 70;
            $this->io()->writeln(
                sprintf('<info>%s/%s</info>-><comment>%s/%s</comment>',
                    $sourceItem->source(), substr($text, 0, $maxLen), $locale, substr($response, 0, $maxLen)));
//            $tKv->commit();
        }
        return $sourceItem; // eh, this is really only if we're tracking translations in source item.
        dd($response);
//        return $tKv->get();

    }

    private
    function OLD()
    {

        // hackish, not a real project!
        $digmusTranslationOwner = $this->findOwner(self::MD_METADATA_OWNER, self::MD_METADATA_SOURCE,
            autoCreate: true, throwErrorIfMissing: false);
        $digmusTranslationProject = $this->findProject($digmusTranslationOwner, self::MD_METADATA_PROJECT,
            autoCreate: true, throwErrorIfMissing: false);
        if ($ownerCode == self::MD_METADATA_OWNER) {
        }

        // not persisted (yet)
        $filter = [];
        if ($ownerCode) {
            $owner = $this->findOwner($ownerCode, $io);
            $filter['entity'] = $owner;
        } elseif ($ownerSource) {
            $filter['entity'] = $this->entityManager->getRepository(Owner::class)->findBy(['source' => $ownerSource]);
        }
        $transCache = [];
        $lookupCache = [];
        $seenOwners = [];
//        foreach ($locales as $targetLocale) {
//            $transCache[$targetLocale] = $this->libreTranslateService->getTranslationCache($projectLocale, $targetLocale, engine: $engine, sourceDir: $sourceDir);
//            $lookupCache[$targetLocale] = $this->libreTranslateService->loadCache($transCache[$targetLocale]);
//        }

        if ($source) {
            $filter['locale'] = $source;
        }
        if ($ownerCode) {
            $owner = $ownerRepository->findOneBy(['code' => $ownerCode]);
            assert($owner, "invalid entity : $ownerCode");
            $filter['entity'] = $owner;
        }
        $projects = $this->projectRepository->findBy($filter);
        // argh
        $io->title(count($projects) . " $ownerCode  $source Projects");
        $accessor = new PropertyAccessor();
        $progressBar = new ProgressBar($io->output(), count($projects));
        $rows = [];
        $index = $this->getMeiliClient()->getIndex('Project');
        $ownerIndex = $this->getMeiliClient()->getIndex('Owner');
        foreach ($projects as $idx => $project) {
            $owner = $project->getOwner();
            if (!in_array($owner->getCode(), $seenOwners)) {
                $seenOwners[$owner->getCode()] = $owner;
            }

            $progressBar->advance();
            foreach ($locales as $targetLocale) {
                // hack since we're passing project in, but we need the digmus translation table
                // until everything else is in source control
//                $digmusTranslationProject->setProjectLocale($project->getProjectLocale());
                assert(false);
                dd($targetLocale, $project->getLocale());
                if ($targetLocale <> $project->getLocale()) {
                    $logger->info(sprintf("%s (%s->%s) %s/%s", $project->getCode(), $project->getProjectLocale(), $targetLocale,
                        $project->getName(), $project->getDescription($targetLocale)));

                    // because of pre-loading the cache, we should probably do this in language pairs.  Or at least cache the cache, but that's going to load everything.

//
//                    // @todo: move to source level?  Easier to store.
//                    $transCache = $this->libreTranslateService->getTranslationCache($project->getProjectLocale(), $targetLocale, engine: $engine,
//                        sourceDir: $ps->getTranslationDir($digmusTranslationProject));
//                    $this->addFileWritten($transCache->getFilename());
//
                    $ps->translateProject($project, $this->libreTranslateService, $targetLocale, $engine, $callable);
//                        dd($project->getDescription(), $project->getCode());
                }

                $rows[] = $this->getTranslationArray($project, $accessor);
            }
            if ($limit && ($idx >= $limit)) {
                break;
            }
            if (($idx % $batchSize) === 0) {
                $entityManager->flush();
                $task = $index->updateDocuments($rows);
//                $this->waitForTask($task);
                $rows = [];
            }


        }
        $task = $index->updateDocuments($rows);

        $ownerRows = [];
        foreach ($seenOwners as $seenOwner) {
            $ownerRows[] = $this->getTranslationArray($seenOwner, $accessor);
        }

//        $this->getIndexEntity('Project', $rows, $io);
//        $this->getIndexEntity('Owner', $ownerRows, $io);

        $progressBar->finish();
        $entityManager->flush();
        $this->showFiles($io);

        return self::SUCCESS;
    }

    private function getTranslationArray($entity, $accessor)
    {
        assertNull($this, "get these from the pixie, not the old class");
//        $updatedRow = [Instance::DB_CODE_FIELD => $entity->getCode()];
//        foreach ($entity->getTranslations() as $translation) {
//            foreach (Instance::TRANSLATABLE_FIELDS as $fieldName) {
//                $translatedValue = $accessor->getValue($translation, $fieldName);
//                $updatedRow['_translations'][$translation->getLocale()][$fieldName] = $translatedValue;
//            }
//        }

        return $updatedRow;
    }

    private
    function getIndexEntity($indexName, $rows, $io)
    {
        $entityIndex = $this->getMeiliClient()->getIndex($indexName);
        $entityIndex->updateDocuments($rows, Instance::DB_CODE_FIELD);
        $this->waitUntilFinished($entityIndex, $io);
    }

    private function populateTranslations(string     $pixieCode,
                                          StorageBox $tKv,
                                          array      $translatableTables = []

    ): array
    {
        $pixieService = $this->pixieService;
        // the base pixie (that we're updating)
        $kv = $pixieService->getStorageBox($pixieCode);
        $config = $pixieService->getConfig($pixieCode);

        // the translations.
        $tKv->select(TranslationService::ENGINE);

        // We could be smarter about this by marking if it's already loaded.
        $where = [];
        foreach ($translatableTables as $tableName) {
            $count = $kv->count($tableName);
            $this->io()->title("injecting translations back into $count items in $tableName");
            $progressBar = new ProgressBar($this->io(), $kv->count($tableName));
            $kv->beginTransaction();
            $rows = $kv->iterate($tableName, $where);
            foreach ($rows as $itemKey => $item) {
                $progressBar->advance();
                $data = $item->getData(true);

                if (!array_key_exists(TranslationService::TRANSLATION_KEY, $data)) {
                    $this->logger->error('missing translation key ' . TranslationService::TRANSLATION_KEY
                        . ' ' . $tableName . '.' . $itemKey);
                    continue;
                }
                $translations = $data[TranslationService::TRANSLATION_KEY];

                $sourceLocale = $data['_locale'] ?? $config->getSource()->locale;

                // these are the source strings om the BASE (not translation) pixie.
                // created during import
                //  the hash is in the row, too
                //
                $sourceFields = $this->getTranslations($data, $sourceLocale);

                foreach ($sourceFields as $translatableField => $sourceTranslation) {
                    // we could batch these keys to optimize
                    $sourceKeys = $this->translationService->getLocalizationData($sourceTranslation,
                        $sourceLocale,
                        $sourceLocale);
                    $translationRows = $tKv->iterate('libre', ['hash' => $sourceKeys['hash']]);
                    foreach ($translationRows as $translation) {
                        $translations[$translation->target()][$translatableField] = $translation->text();
                    }
                }
                // all the translations, by locale
                $data[TranslationService::TRANSLATION_KEY] = $translations;
                $kv->set($data, $tableName);
            }
            $progressBar->finish();
            $kv->commit();
        }

        return [];
    }

    private function getTranslations(array $data, string $sourceLocale): array
    {
        return $data[TranslationService::TRANSLATION_KEY][$sourceLocale] ?? [];
    }

    private function getTranslatableTables(Config $config, ?string $tableFilter = null): array
    {
        $translatableTables = [];
        foreach ($config->getTables() as $tableName => $table) {
            if ($tableFilter && ($tableName <> $tableFilter)) {
                continue;
            }
            if (count($table->getTranslatable()) == 0) {
                continue;
            }
            $translatableTables[] = $tableName;
        }
        return $translatableTables;
    }

    /**
     * Go through the _translations keys in the base pixie and add
     * individual translation records to the ENGINE table if they don't exist.
     *
     * Then translate will dispatch messages to translate them.
     *
     * @param string $pixieCode
     * @param string|null $marking
     * @param string|null $tableFilter
     * @param array $locales
     * @param StorageBox $tKv
     * @param bool $populateKeys
     * @return array|void
     * @throws \Exception
     */
    private function populateTranslationKeys(string     $pixieCode,
                                             ?string    $marking,
                                             array      $translatableTables,
                                             array      $locales,
                                             StorageBox $tKv,

    )
    {
        $tKv->select(TranslationService::ENGINE); // since we're writing
        $pixieService = $this->pixieService;
        $kv = $pixieService->getStorageBox($pixieCode);
        $config = $pixieService->getConfig($pixieCode);

        $where = [];
        if ($marking) {
//            $where = ['marking' => $marking];
        }
        $counts = [];
        foreach ($translatableTables as $tableName) {
            $tableCount = $kv->count($tableName);
            $counts[$tableName] = ['new' => 0, 'total' => $tableCount];
            $this->io()->title("populating  $tableName ($tableCount)");

//            foreach ($table->getTranslatable() as $translatableField) {
            $tKv->beginTransaction();
            $rows = $kv->iterate($tableName, $where);
            $progressBar = new ProgressBar($this->io(), $tableCount);
            foreach ($rows as $item) {

                $progressBar->advance();
                $data = $item->getData(true);
                $sourceLocale = $data['_locale'] ?? $config->getSource()->locale;
                if (!array_key_exists('_translations', $data)) {
                    continue;
                }
//                assert($data->_translations??null, "no _translations in $tableName");
//                assert($data->_translations?->{$sourceLocale}, "missing source _translations $sourceLocale");

                // these are the source strings.  the hash is in the row, too
                // these were created during import
                $sourceFields = $data['_translations'][$sourceLocale] ?? [];
                foreach ($sourceFields as $translatableField => $sourceTranslation) {
                    // if a translation already exists, skip this
                    // is this still necessary?  Put everything in libre?
                    $sourceKeys = $this->translationService->getLocalizationData($sourceTranslation,
                        $sourceLocale,
                        $sourceLocale);

                    if (!$tKv->has($hash = $sourceKeys['hash'])) {
                        dd("$hash missing from source!  It should happen during import");
                        $sourceKeys['table_name'] = $tableName;
                        $sourceKeys['prop'] = $translatableField;
                        $tKv->set($sourceKeys, 'source');
                    }

                    foreach ($locales as $targetLocale) {
                        assert(($targetLocale <> $sourceLocale)
                            || array_key_exists($targetLocale, $data['_translations']),
                            "the $sourceLocale translations should already be in _translations, during import "
                        );
                        if ($targetLocale === $sourceLocale) {
                            // although redundant, it's complicated to track when a string is source and when it's a translation
                            continue;
                        }
                        $existing = $data['_translations'][$targetLocale] ?? [];
                        // already translated, could confirm that these keys exist, but they came from there.
                        if (array_key_exists($translatableField, $existing)) {
                            continue;
                        }

                        $sourceKey = $data[$translatableField];
                        $transKeys = $this->translationService->getLocalizationData($sourceTranslation,
                            $sourceLocale,
                            $targetLocale);
                        assert($sourceTranslation, "empty source translation!");
//                        dd($sourceKey, $translatableField, $sourceTranslation, $transKeys);

                        if ($sourceKey !== ($calc = $transKeys['hash'])) {
                            // hack for related tables
                        }
//                        dump($transKeys, $data, sourceKey: $sourceKey, sourceTranslation: $sourceTranslation);
//                        assert($sourceKey == ($calc = $transKeys['hash']), "$sourceKey <> calc $calc for " . $sourceTranslation);
                        $counts[$tableName]['total']++;

                        if (!$tKv->has($transKeys['key'],
                            preloadKeys: true, where: $preloadWhere = ['target' => $targetLocale])) {
                            $counts[$tableName]['new']++;
                            $transKeys['marking'] = TranslationService::NOT_TRANSLATED;
                            // testing only, because it's in a slow transaction!

                            $transKeys['table_name'] = $tableName;
                            $transKeys['prop'] = $translatableField;
                            $tKv->set($transKeys, where: $preloadWhere);
                        }
                    }
                }
                if ($limit = $this->io()->input()->getOption('limit')) {
                    if ($progressBar->getProgress() >= $limit) {
                        break;
                    }
                }
                $batch = $this->io()->input()->getOption('batch');
                if ($batch && ($progressBar->getProgress() % $batch) == 0) {
//                    $this->io()->writeln("commiting to database...");
                    $tKv->commit();
                    $tKv->beginTransaction();
                }
            }
            $progressBar->finish();
            $tKv->commit();
            $counts[$tableName]['db_total'] =
                $tKv->count(where: ['table_name' => $tableName]);
            //new keys?
        }
        return $counts;
    }
}
