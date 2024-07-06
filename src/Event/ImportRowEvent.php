<?php


namespace Survos\PixieBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class ImportRowEvent extends Event
{

    public function __construct(
        public array $row,
        public ?int $index=null,
    )
    {
    }
}
