<?php

namespace Survos\PixieBundle\Event;

use App\Entity\Catalog;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Symfony\Contracts\EventDispatcher\Event;

class StorageBoxEvent extends Event
{
    private StorageBox $storageBox;

    public function __construct(
        private string $pixieCode,
        private array $tags=[]
    ) {

    }

    public function getPixieCode(): string
    {
        return $this->pixieCode;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getStorageBox(): StorageBox
    {
        return $this->storageBox;
    }

    public function setStorageBox(StorageBox $storageBox): StorageBoxEvent
    {
        $this->storageBox = $storageBox;
        return $this;
    }

}
