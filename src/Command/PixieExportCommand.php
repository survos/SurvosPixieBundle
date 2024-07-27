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

#[AsCommand('pixie:export', 'Import a table to csv/json"')]
final class PixieExportCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag, private readonly PixieService $pixieService,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                                          $io,
        PixieService                                          $pixieService,
        PixieImportService                                    $pixieImportService,
        #[Argument(description: 'config code')] string        $pixieCode,
        #[Argument(description: 'table name')] string         $tableName,
        #[Option(description: 'output directory/filename')] ?string $dirOrFilename,
        #[Option(description: "overwrite existing output files")] bool                      $overwrite = false,
        #[Option(description: "max number of records per table to export")] int                     $limit = 0,

    ): int
    {
        $this->initialized = true;
        $kv = $pixieService->getStorageBox($pixieCode);
        $config = $pixieService->getConfig($pixieCode);

        $config = $pixieService->getConfig($pixieCode);
        assert($config, $config->getConfigFilename());
        if (empty($dirOrFilename)) {
            $dirOrFilename = $pixieService->getSourceFilesDir($pixieCode);
        }

        assert($kv->tableExists($tableName), "Missing table $tableName: \n".join("\n", $kv->getTableNames()));

        // now iterate
        $table = $config->getTables()[$tableName]; // to get views
        foreach ($kv->iterate($tableName) as $row) {
            dd($row);
        }


//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Pixie databases go in datadir, not with their source? Or defined in the config
        if (!is_dir($dirOrFilename)) {
            $io->error("$dirOrFilename does not exist.  set the directory in config or pass it as the first argument");
            return self::FAILURE;
        }


        // export?

        $io->success('Pixie:export success ' . $pixieCode);
        return self::SUCCESS;
    }


}
