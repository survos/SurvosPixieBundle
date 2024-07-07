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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:iterate', 'Iterative over a pixie database, sending events"')]
final class IterateCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;
    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private EventDispatcherInterface $eventDispatcher,
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
        // do we need the Config?  Or is it all in the StorageBox?
        $kv = $pixieService->getStorageBox(
            $pixieService->getPixieFilename($configCode)
        );
        foreach ($kv->getTables() as $tableName) {
            $io->title($tableName);
            $kv->select($tableName);
            $progressBar = new ProgressBar($io, $kv->count());
            $idx = 0;
            foreach ($kv->iterate() as $key => $row) {
                $idx++;
                $this->eventDispatcher->dispatch(
                    new RowEvent($configCode, $tableName, $row,
                        $key,
                        $idx,
                        self::class)
                );
                if ($limit && $idx >= $limit) {
                    break;
                }
                $progressBar->advance();
            }
            $progressBar->finish();
        }

        $io->success('Pixie:import success ' . $kv->getFilename());
        return self::SUCCESS;
    }

}
