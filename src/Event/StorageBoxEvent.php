<?php

namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\StorageBox;
use Symfony\Contracts\EventDispatcher\Event;

class StorageBoxEvent extends Event
{
    private StorageBox $storageBox;

    public function __construct(
        private readonly string $pixieCode,
        private readonly ?string $filename=null,
        /** @deprecated "Use mode instead" */
        private readonly bool $isTranslation=false,
        private readonly string $mode='data', # data|translation|image
        private readonly array $tags=[]
    ) {

    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isTranslation(): bool
    {
        return $this->isTranslation;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
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
