<?php

namespace Survos\PixieBundle\Service;

// see https://github.com/bungle/web.php/blob/master/sqlite.php for a wrapper without PDO

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Stopwatch\Stopwatch;


class PixieService
{
    // cache, indexed by filename
    private array $storageBoxes = [];

    public function __construct(
        private bool $isDebug,
        private array $data=[],
        private string $dataDir='./',
        private ?LoggerInterface $logger=null,
        private ?Stopwatch $stopwatch=null,
        private ?PropertyAccessorInterface $accessor=null
    )
    {
    }

    private function resolveFilename($filename): string
    {
        if (!file_exists($filename)) {
            $filename = $this->dataDir . "/$filename";
        }
        return $filename;
    }

    function getStorageBox(string $filename, array $tables=[], bool $destroy=false): StorageBox
    {
        $filename = $this->resolveFilename($filename);
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



}
