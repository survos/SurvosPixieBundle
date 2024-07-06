<?php


namespace Survos\PixieBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class ImportFileEvent extends Event
{

    public function __construct(
        public string $filename
    )
    {
    }
}
