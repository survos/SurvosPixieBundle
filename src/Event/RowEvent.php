<?php


namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\StorageBox;
use Symfony\Contracts\EventDispatcher\Event;

class RowEvent extends Event
{

    public const string PRE_LOAD='pre_load';
    public const string LOAD='load';
    public const string POST_LOAD='post_load';

    public function __construct(
        public string $configCode,
        public string $tableName,
        public ?array $row, // return null to not add to database
        public int|string|null $key=null,
        public ?int $index=null,
        public ?int $total=null, // so we can act on the first or last row, add a progressBar, etc.
        public ?string $type=self::LOAD, // defaults to regular row load
        public ?string $action=null,
        public ?StorageBox $storageBox=null // so we can update other tables
    )
    {
    }

    public function isPreLoad(): bool
    {
        return $this->type === self::PRE_LOAD;
    }
    public function isRowLoad(): bool
    {
        return $this->type === self::LOAD;
    }
    public function isPostLoad(): bool
    {
        return $this->type === self::POST_LOAD;
    }
}
