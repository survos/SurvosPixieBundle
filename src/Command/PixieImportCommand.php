<?php

namespace Survos\PixieBundle\Command;

use JsonMachine\Items;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:import', 'Import csv to Pixie, a file or directory of files"', aliases: ['import'])]
final class PixieImportCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ?ProgressBar $progressBar=null;
    private $total = 0; // hack to bypass count for large JSON files, e.g. smk

    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag, private readonly PixieService $pixieService,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                                           $io,
        PixieService                                                                                 $pixieService,
        PixieImportService                                                                           $pixieImportService,
        #[Argument(description: 'config code')] string                                               $configCode,
        #[Option(description: 'conf filename, default to directory name of first argument')] ?string $dir,
        #[Option(description: "max number of records per table to import")] ?int                     $limit = null,
        #[Option(description: "overwrite records if they already exist")] bool                       $overwrite = false,
        #[Option(description: "index after import (default: true)")] ?bool                           $index = null,
        #[Option(description: "purge db file first")] bool                                           $reset = false,
        #[Option(description: "Batch size for commit")] int                                          $batch = 500,
        #[Option(description: "total if known (slow to calc)")] int                                  $total = 0,
        #[Option('start', description: "starting at (offset)")] int                                  $startingAt = 0,
        #[Option(description: "table search pattern")] string                                        $pattern = '',
        #[Option(description: 'tags (for listeners)')] ?string                                       $tags=null,

    ): int
    {
        $this->initialized = true;
        // not sure how to auto-wire this in the constructor
        // idea: if conf doesn't exist, require a directory name and create it, a la rector
        if (empty($configCode)) {
            $configCode = pathinfo($dir, PATHINFO_BASENAME);
        }

        $index = is_null($index) ? true: $index;

        $config = $pixieService->getConfig($configCode);
        assert($config, $config->getConfigFilename());
        $dir = $pixieService->getSourceFilesDir($configCode, dir: $dir);

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config
        if (!is_dir($dir)) {
            $io->error("$dir does not exist.  set the directory in config or pass it as the first argument");
            return self::FAILURE;
        }

//        if (!file_exists($configFilename) && (file_exists($configWithCsv = $dirOrFilename . "/$config"))) {
//            $config = $configWithCsv;
//        }
//
//        if (!file_exists($config) && (file_exists($configInPackages = $this->bag->get('kernel.project_dir') . "/config/packages/Pixie/$config"))) {
//            $config = $configInPackages;
//        }
//
//        if (!file_exists($config)) {
//            $config = $dirOrFilename . "/$config";
//        }

        $pixieDbName = $pixieService->getPixieFilename($configCode);
        if (!file_exists($dirName = pathinfo($pixieDbName, PATHINFO_DIRNAME))) {
            mkdir($dirName, 0777, true);
        }
        if ($reset) {
            $this->pixieService->destroy($pixieDbName);
        }
        $this->total = $total;
        if ($limit && !$this->total) {
            $this->total = $limit;
        }
        if ($config->getSource()->total) {
            $this->total = $config->getSource()->total;
        }

        $limit = $this->pixieService->getLimit($limit);
        $pixieImportService->import($configCode, $config, limit: $limit, startingAt: $startingAt,
            context: [
                'tags' => explode(",", $tags),
            ],
            overwrite: $overwrite, pattern: $pattern,
            callback: function ($row, $idx, StorageBox $kv) use ($batch, $limit) {
                $finished = $limit ? $idx > ($limit) : false;
//                dd($limit, $idx, $finished, $batch);
                if ($finished || (($idx % $batch) == 0) ) {
                    $this->logger->info("Saving $batch, now at $idx of $limit");
                    $kv->commit();
                    $kv->beginTransaction();
                };
//                return true; // return true to continue
                return $limit ?  !$finished: true; // break if we've hit the limit
            });

        $kv = $this->pixieService->getStorageBox($configCode);
        $consoleTable = new Table($io);
        $consoleTable->setHeaders(['table','count']);
        foreach ($config->getTables() as $table) {
            $consoleTable->addRow([$table->getName(), $kv->count($table->getName())]);
        }
        $consoleTable->render();
        $io->success($this->getName() . ' success ' . $pixieDbName);

        if ($index) {
            $this->runIndex($configCode);
        }
        return self::SUCCESS;
    }


    #[AsEventListener(event: ImportFileEvent::class)]
    public function startImport(ImportFileEvent $event): void
    {
        if (!$this->initialized) {
            return;
        }
        $this->io()->title($event->filename);
        if (!$count = $this->total) {
            if ($event->getType() == 'json') {
                // faster to get the first record and filesize and divide, for a rough estimate.
                $iterator = Items::fromFile($event->filename);
                $first = $iterator->getIterator()->current();
                $pointer = $iterator->getCurrentJsonPointer();
                $size = filesize($event->filename);
                $guess = (int)($size / strlen(json_encode($first, JSON_PRETTY_PRINT)));
                $count = $guess;
//                dump($guess);
//                dd("plz set total in config");
//                    halaxa/json-machine
//                try {
//                    $count =  iterator_count(Items::fromFile($event->filename));
//                    dd($count, $guess);
//                } catch (\Exception $e) {
//                    $this->logger->error($e->getMessage() . "\n" . $event->filename);
//                    throw $e;
//                }
            } else {
                $count = $this->lineCount($event->filename);
            }
        }

        $this->progressBar = new ProgressBar($this->io()->output(), $count);
//        $this->progressBar->start($count);
    }

    private function lineCount(string $filename)
    {
//        return count(file($filename));
        $lines_command = sprintf('wc -l %s', $filename);
        $lines = system($lines_command);
        return (int)$lines;
    }


    #[AsEventListener(event: RowEvent::class)]
    public function importRow(RowEvent $event): void
    {
        switch ($event->type) {
            case $event::PRE_LOAD:
                break;
            case $event::LOAD:
                $this->progressBar?->advance();
                break;
            case $event::POST_LOAD:
                $this->progressBar?->finish();
                break;
        }
//        $this->initialized && $event->isRowLoad() && $this->progressBar->advance();
    }

    public function runIndex(string $pixieCode)
    {
        $cli = 'pixie:index ' . $pixieCode;
        $this->io()->warning('bin/console ' . $cli);
        $this->runCommand($cli);
    }

}
