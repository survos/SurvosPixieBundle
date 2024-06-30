<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Debug;

use Doctrine\Persistence\ObjectManager;
use Meilisearch\Bundle\Collection;
use Meilisearch\Bundle\SearchService;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Stopwatch\Stopwatch;

final class TraceableStorageBox extends StorageBox
{
//    private Stopwatch $stopwatch;
    #[NoReturn] function __construct(private string                    $filename,
                                     private array                     &$data, // debug data, passed from Pixie
                                     private array                     $tablesToCreate = [],
                                     private array                     $regexRules = [],
                                     private ?string                   $currentTable = null,
                                     private ?int                      $version = 1,
                                     private string                    $valueType = 'json', // eventually jsonb
                                     private bool                      $temporary = false, // nyi
                                     private readonly ?LoggerInterface $logger = null,
                                     private readonly ?PropertyAccessorInterface  $accessor = null,
                                     private array                     $formatters = [],
                                     private readonly ?Stopwatch       $stopwatch = null,

    )
    {

        parent::__construct(...func_get_args());
//        $this->stopwatch = $stopwatch;
    }

    public function map(array $tableRegexRules, array $tables = ['__all'])
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    public function getVersion(): string
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    public function get(string $key, string $table = null): string|object|array|null
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    protected function query(string $sql, array $variables = []): \PDOStatement
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }


    public function innerSearchService(string $function, array $args): mixed
    {
        $this->stopwatch->start($function);

        $result = parent::{$function}(...$args);

        $event = $this->stopwatch->stop($function);

        $this->data[$this->getFilename()][$function][] = [
            '_params' => $args,
            '_results' => $result,
            '_duration' => $event->getDuration(),
            '_memory' => $event->getMemory(),
        ];
        return $result;
    }




    public function clear(): void
    {
        $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    public function delete(string $key, string $table = null): bool
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }


    public function count(string $table = null): int
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

}
