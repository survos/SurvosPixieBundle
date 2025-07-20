<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Debug;

use Doctrine\Persistence\ObjectManager;
use Meilisearch\Bundle\Collection;
use Meilisearch\Bundle\SearchService;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

final class TraceableStorageBox extends StorageBox
{
//    private Stopwatch $stopwatch;
    function __construct(private readonly string                    $filename,
                                     private array                     &$data, // debug data, passed from Entity
                                     private readonly ?Config $config=null, // for creation only.  Shouldn't be in constructor!
                                     private readonly ?string                   $currentTable = null,
                                     private readonly ?int                      $version = 1,
                                     private readonly string                    $valueType = 'json', // eventually jsonb
                                     private readonly bool                      $temporary = false, // nyi
                                     private readonly ?LoggerInterface $logger = null,
                                     private readonly ?PropertyAccessorInterface  $accessor = null,
                                     private readonly ?SerializerInterface $serializer=null,
                                     private readonly array                     $formatters = [],
                                     private readonly ?Stopwatch       $stopwatch = null,
                                     private readonly ?string $pixieCode=null, //
                                     private readonly array $templates=[],

    )
    {

        parent::__construct(...func_get_args());
//        $this->stopwatch = $stopwatch;
    }

//    public function mapHeader(array $header, string $propertyRule, string $tableName = null, array $regexRules = []): array
//    {
//        return $this->innerSearchService(__FUNCTION__, \func_get_args());
//    }

    #[\Override]
    public function getVersion(): string
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    #[\Override]
    public function get(string $key, ?string $tableName = null, ?callable $callback=null): ?Item // string|object|array|null
//    public function get(string $key, string $table = null): string|object|array|null
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    #[\Override]
    public function query(string $sql, array $variables = []): \PDOStatement
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }


    public function innerSearchService(string $function, array $args): mixed
    {
        $this->stopwatch->start($function);

        $result = parent::{$function}(...$args);
//        $result = parent::{$function}(...$args);

        $event = $this->stopwatch->stop($function);

        $this->data[$this->getFilename()][$function][] = [
            '_params' => $args,
            '_results' => $result,
            '_duration' => $event->getDuration(),
            '_memory' => $event->getMemory(),
        ];
        return $result;
    }




    #[\Override]
    public function clear(): void
    {
        $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

    #[\Override]
    public function delete(string $key, ?string $table = null): bool
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }


    #[\Override]
    public function count(?string $table = null, array $where=[]): ?int
    {
        return $this->innerSearchService(__FUNCTION__, \func_get_args());
    }

}
