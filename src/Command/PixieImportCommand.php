<?php

namespace Survos\PixieBundle\Command;

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
    private ProgressBar $progressBar;
    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                    $io,
        PixieService                                                            $pixieService,
        PixieImportService                                                      $pixieImportService,
        #[Argument(description: '(string)')] string                             $dirOrFilename = '',
        #[Option(shortcut: 'c', description: 'conf filename, default to directory name of first argument')]
        string                                                                  $configCode = null,
        #[Option(description: "max number of records per table to import")] int $limit = 0,
        #[Option(description: "Batch size for commit")] int                     $batch = 500,

    ): int
    {
        $this->initialized = true;
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


        $pixieImportService->import($configCode, $config, limit: $limit,
            callback: function ($row, $idx, StorageBox $kv) use ($batch) {
//            dd($row);
                if (($idx % $batch) == 0) {
                    $this->logger->info("Saving $batch, now at $idx");
                    $kv->commit();
                    $kv->beginTransaction();
                };
                return true;
            });
        $io->success('Pixie:import success ' . $pixieDbName);
        return self::SUCCESS;
    }


    #[AsEventListener(event: ImportFileEvent::class)]
    public function showFile(ImportFileEvent $event): void
    {
        if (!$this->initialized) {
            return;
        }
        $this->io()->title($event->filename);
        $this->progressBar = new ProgressBar($this->io()->output());
    }

    #[AsEventListener(event: RowEvent::class)]
    public function importRow(RowEvent $event): void
    {
        $this->initialized && $event->isRowLoad() && $this->progressBar->advance();
    }
}
