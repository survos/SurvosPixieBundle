<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:export', "Export a table to csv/json")]
final class PixieExportCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private SerializerInterface $serializer,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                      $io,
        PixieService                                                            $pixieService,
        PixieImportService                                                      $pixieImportService,
        #[Argument(description: 'config code')] ?string                         $configCode,
        #[Argument(description: 'table name')] ?string                          $tableName,
        #[Option(description: 'output directory/filename')] ?string             $dirOrFilename,
        #[Option(description: 'new key name')] ?string                          $key,
        #[Option(description: 'single value (map)')] ?string                    $value,
        #[Option(description: 'comma-delimited values key => array')] ?string   $values,
        #[Option(description: "overwrite existing output files")] bool          $overwrite = false,
        #[Option(description: "max number of records per table to export")] int $limit = 0,

    ): int
    {
        $configCode ??= getenv('PIXIE_CODE');
        $this->initialized = true;
        $kv = $pixieService->getStorageBox($configCode);
        $config = $pixieService->selectConfig($configCode);
        assert($config, $config->getConfigFilename());
        if (empty($dirOrFilename)) {
            $dirOrFilename = $pixieService->getSourceFilesDir($configCode);
        }

        assert($kv->tableExists($tableName), "Missing table $tableName: \n".implode("\n", $kv->getTableNames()));

        $recordsToWrite=[];
        $key ??= 'key';
        // now iterate
        $table = $config->getTables()[$tableName]; // to get views, key
        $count = 0;
        foreach ($kv->iterate($tableName) as $row) {
            // dispatch a export event

            $recordsToWrite[$row->{$key}()] = $value ? $row->{$value}() : $row;
            if ($limit && (++$count >= $limit)) {
                break;
            }
        }

        $filename = $configCode . '-' . $tableName.'.json';

        file_put_contents($filename, $this->serializer->serialize($recordsToWrite, 'json'));
        $io->success(count($recordsToWrite) . " records written to $filename");

//        dump($configData, $config->getVersion());
//        dd($dirOrFilename, $config, $configFilename, $pixieService->getPixieFilename($configCode));

        // Entity databases go in datadir, not with their source? Or defined in the config
        if (!is_dir($dirOrFilename)) {
            $io->error("$dirOrFilename does not exist.  set the directory in config or pass it as the first argument");
            return self::FAILURE;
        }


        // export?

        $io->success('Entity:export success ' . $configCode);
        return self::SUCCESS;
    }




}
