<?php


namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\StorageBox;
use Symfony\Contracts\EventDispatcher\Event;

class RowEvent extends Event
{

    public function __construct(
        public string $configCode,
        public string $tableName,
        public ?array $row, // return null to not add to database
        public int|string|null $key=null,
        public ?int $index=null,
        public ?string $action=null,
        public ?StorageBox $storageBox=null // so we can update other tables
    )
    {
    }
}
