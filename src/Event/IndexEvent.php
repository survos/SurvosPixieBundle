<?php


namespace Survos\PixieBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class IndexEvent extends Event
{

    public function __construct(
        public string $pixieCode,
        public ?string $subCode,
        public string $tableName,
        public array $stats,
        public ?int $imageCount=null, // $iKv->count()
    )
    {
    }
}
