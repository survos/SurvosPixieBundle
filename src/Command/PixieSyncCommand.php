<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\BunnyBundle\Service\BunnyService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;
use Zenstruck\Filesystem\Archive\ZipFile;
use ZipArchive;

#[AsCommand('pixie:sync', "Upload/download directories (as zip) to/from bunny")]
final class PixieSyncCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private readonly ?BunnyService $bunnyService=null,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                      $io,
        PixieService                                                            $pixieService,
        PixieImportService                                                      $pixieImportService,
        #[Argument(description: 'config code')] ?string                          $configCode,
        #[Argument(description: "comma-delimited dirs (raw,json,pixie")] string $dirs='json',
        #[Option(description: "upload to bunny")] bool          $upload = false,
        #[Option(description: "download from bunny")] bool          $download = false,

//        #[Option(description: "/raw")] bool          $raw = false,
//        #[Option(description: "/json")] bool          $json = false,
//        #[Option(description: "/pixie")] bool          $pixie = false,
    ): int
    {
        if (!$this->bunnyService) {
            $this->io()->error("composer req survos/bunny-bundle");
            return self::FAILURE;
        }
        $configCode ??= getenv('PIXIE_CODE');
        $this->initialized = true;
        $config = $pixieService->selectConfig($configCode);
        assert($config, $config->getConfigFilename());
        if (empty($dirOrFilename)) {
            $dirOrFilename = $pixieService->getSourceFilesDir($configCode);
        }
        if (!$upload && !$download) {
            $io->error("You must specify --upload or --download");
            return self::FAILURE;
        }

        $baseDir = $this->pixieService->getSourceFilesDir($configCode);
        $zipDir = $this->pixieService->getDataRoot() . "/_zip";
        if (!is_dir($zipDir)) {
            mkdir($zipDir, 0777, true);
        }

        foreach (explode(',', $dirs) as $dir) {
            $zipFilename = $zipDir . "/$configCode-$dir.zip";
            $localDir = $baseDir . "/$dir";
            if ($upload) {
                $io->writeln("zip $localDir to $zipFilename ");
                $this->zip($localDir, $zipFilename);
//                $this->bunnyService->uploadFile();
            }
            if ($download) {
                $io->writeln("unzip $zipFilename to $localDir ");
                $this->unzip($zipFilename, $localDir);
            }
        }

        $io->success($this->getName() .  ' success ' . $configCode);
        return self::SUCCESS;
    }

    private function unzip(string $filename, string $dir)
    {
        $zip = new ZipArchive();
        if ($zip->open($filename)) {
            $zip->extractTo($dir);
            $zip->close();
        } else {
            $this->io()->error("Unable to unzip $filename to $dir");
        }
    }

    private function zip(string $dir, string $filename)
    {
        // compress a local directory (all files (recursive) in "some/local/directory" are added to archive)
        if (file_exists($filename)) {
            unlink($filename);
        }
        $this->io()->title($filename . " ($dir)");
        $zipFile = ZipFile::compress(new \SplFileInfo($dir), $filename);
        $table = new Table($this->io()->output());
        $table->setHeaders(['file','size']);
        foreach ($zipFile->directory() as $file) {
            $table->addRow([$file->path()->toString(), $file->size()]);
        };
        $table->render();
        return;

        assert(is_dir($dir));
        $zip = new ZipArchive();
        $ret = $zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->io()->writeln("Writing $filename");
        if ($ret !== TRUE) {
            printf('Failed with code %d', $ret);
        } else {
            $finder = new Finder();
            foreach ($finder->in($dir) as $file) {

            }
            $options = ['add_path' => $dir, 'remove_all_path' => TRUE];
//            $zip->addPattern($dir);
            $this->io()->writeln($dir);
            dump($options);
            $zip->addGlob('*', GLOB_BRACE, $options);
            $zip->close();
        }

    }


}
