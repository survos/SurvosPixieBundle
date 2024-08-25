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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:import', 'Import csv to Pixie, a file or directory of files"')]
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
        IO                                                                                          $io,
        PixieService                                                                                $pixieService,
        PixieImportService                                                                          $pixieImportService,
        #[Argument(description: 'config code')] string                                              $configCode,
        #[Option(description: 'conf filename, default to directory name of first argument')] ?string $dirOrFilename,
        #[Option(description: "max number of records per table to import")] int                     $limit = 0,
        #[Option(description: "overwrite records if they already exist")] bool                      $overwrite = false,
        #[Option(description: "purge db file first")] bool                                          $reset = false,
        #[Option(description: "Batch size for commit")] int                                         $batch = 500,
        #[Option(description: "total if known (slow to calc)")] int                                         $total = 0,

    ): int
    {
        $this->initialized = true;
        $this->total = $total;
        // not sure how to auto-wire this in the constructor
        // idea: if conf doesn't exist, require a directory name and create it, a la rector
        if (empty($configCode)) {
            $configCode = pathinfo($dirOrFilename, PATHINFO_BASENAME);
        }

        $config = $pixieService->getConfig($configCode);
        assert($config, $config->getConfigFilename());
        if (empty($dirOrFilename)) {
            $dirOrFilename = $pixieService->getSourceFilesDir($configCode);
        }

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config
        if (!is_dir($dirOrFilename)) {
            $io->error("$dirOrFilename does not exist.  set the directory in config or pass it as the first argument");
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

        $pixieImportService->import($configCode, $config, limit: $limit, overwrite: $overwrite,
            callback: function ($row, $idx, StorageBox $kv) use ($batch, $limit) {
//                $this->progressBar->advance();
                $finished = $idx >= $limit;
                if ($finished || ($idx % $batch) == 0) {
                    $this->logger->info("Saving $batch, now at $idx");
                    $kv->commit();
                    $kv->beginTransaction();
                };
                return !$finished; // break if we've hit the limit
            });
        $io->success($this->getName() . ' success ' . $pixieDbName);
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
//                    halaxa/json-machine
                try {
                    $count =  iterator_count(Items::fromFile($event->filename));
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage() . "\n" . $event->filename);
                    throw $e;
                }
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
}
