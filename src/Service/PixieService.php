<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Stopwatch\Stopwatch;


class PixieService
{
    // cache, indexed by filename
    private array $storageBoxes = [];

    public function __construct(
        private bool                                        $isDebug,
        private array                                       $data=[],
        private string                                      $extension = "pixie.db",
        private string                                      $dbDir='./pixie',
        private string                                      $dataRoot='./data',
        private string                                      $configDir='/config/packages/pixie',
        #[Autowire('%kernel.project_dir%')] private ?string $projectDir=null,
        private ?LoggerInterface                            $logger=null,
        private ?Stopwatch                                  $stopwatch=null,
        private ?PropertyAccessorInterface                  $accessor=null
    )
    {
    }

    public function getPixieFilename(string $pixieCode)
    {
        $filename = $this->projectDir . "/" . $this->dbDir . "/$pixieCode.{$this->extension}";

        if (file_exists($filename)) {
            $filename = realpath($filename);
        }
        return $filename;

    }

    private function resolveFilename($filename, string $type=null): ?string
    {

        if ($type && !file_exists($filename)) {
            $root = match($type) {
                'db' => $this->dbDir,
                'config' => $this->configDir,
                'data' => $this->dataRoot,
            };
            $filename = $root . "/$filename";
            if (!file_exists($filename)) {
                $filename = $this->projectDir . "/$filename";
            }
        }
        return file_exists($filename) ? $filename : null;
    }

    function getStorageBox(string $filename, array $tables=[], bool $destroy=false): StorageBox
    {
        if (!file_exists($filename)) {
            $filename = $this->getPixieFilename($filename);
        }
//        $filename = $this->resolveFilename($filename);
        $destroy && $this->destroy($filename);
        if (!$kv = $this->storageBoxes[$filename]??false) {
            $class = $this->isDebug ? TraceableStorageBox::class : StorageBox::class;
            $kv =  new $class($filename,
                $this->data, // for debug
                $tables,
                accessor: $this->accessor,
                logger: $this->logger, stopwatch: $this->stopwatch);
            $this->storageBoxes[$filename] = $kv;
        }
        return $kv;
    }

//    function getStringBox(string $filename, array $tables=[]): StorageBox
//    {
//        return new StorageBox($filename, $tables, valueType: 'string', logger: $this->logger);
//    }

    function destroy(string $filename): void
    {
        $filename = $this->resolveFilename($filename);
        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    /** @internal used in the DataCollector class */
    public function getData(): array
    {
        return $this->data;
        foreach ($this->storageBoxes as $filename => $storageBox) {
            $this->data[$filename] = $storageBox->getData();
        }
        return $this->data;
    }

    public function getConfigFiles(): array
    {
        $finder = new Finder();
        $configs  = [];
        foreach ($finder->files()->name('*.yaml')->in($this->getConfigDir()) as $file) {
            // we can optimize later...
            $config = new Config($file->getRealPath());
            $code = $file->getFilenameWithoutExtension();
            $resolvedDataPath = $this->resolveFilename($config->getDataDirectory(), 'data');
            $config->dataDir = $resolvedDataPath;
            $config->pixieFilename = $this->getPixieFilename($code);
            $configs[$code] = $config;
        }
        return $configs;
//        // we could parse these, though then we should cache them.  Since they're in config, we could cache them at compile-time
//        return glob($this->getConfigDir() . '/*.yaml');

    }


    public function getConfig(string $pixieCode): Config
    {
        // @todo: handle non-standard locations
        return new Config($this->getConfigDir() . "/$pixieCode.yaml" );
    }

    public function getConfigDir(bool $autoCreate=false): string
    {
        $dir = $this->configDir;
        if (!file_exists($dir)) {
            $dir = $this->projectDir . $dir;
        }

        if ($autoCreate && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;

    }

    public function getDataRoot(string $pixieCode, ?Config $config=null): string
    {
        if (!$config) {
            $config = $this->getConfig($pixieCode);
        }

        if (!$dir = $config->getDataDirectory()) {
            $this->dataRoot . "/$pixieCode";
        }

        if (!file_exists($dir)) {
            $dir = $this->projectDir . "/" .  $this->dataRoot . "/$dir";
        }
//        dd($dir);
//        if (!file_exists($dir)) {
//            $dir = $this->projectDir . "/" . $config->getDataDirectory();
//        }
//        dd($dir, $this->dataDir, $config->getDataDirectory());
        assert(file_exists($dir), $dir);
        return realpath($dir);


    }



}
