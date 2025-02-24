<?php // this could also be in sais


namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\StorageBox;
use Symfony\Contracts\EventDispatcher\Event;

class ImageEvent extends Event
{

    public function __construct(
        public string $pixieCode,
        public string $imageCode,
        public StorageBox $iKv,
        public array $data,
    )
    {
    }
}
