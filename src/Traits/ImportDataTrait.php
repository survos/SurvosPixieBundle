<?php

namespace Survos\PixieBundle\Traits;

use App\Model\InstanceData;
use Survos\PixieBundle\Entity\KeyValue;

trait ImportDataTrait
{
    //    /** @ORM\Embedded(class="KeyValue",  columnPrefix = "import_") */
    //    private $importDataWrapper;

    //    private $rawLabel; // so that it can be used as a reference, e.g. DAVID ROY

    //    public function __construct()
    //    {
    //        $this->importDataWrapper = new KeyValue();
    //    }

    public function getRawData(): ?array
    {
        return $this->importDataWrapper->getRawData();
    }

    public function getImportData(): ?array
    {
        return $this->importDataWrapper?->getData();
    }

    public function getInstanceData(): InstanceData
    {
        return new InstanceData($this->getImportData());
    }

    public function setImportData(array $rawData): self
    {
        if (empty($this->importDataWrapper)) {
            $this->importDataWrapper = new KeyValue();
        }
        $this->importDataWrapper->setData($rawData);
        return $this;
    }

    public function setRawData(array $rawData): self
    {
        if (empty($this->importDataWrapper)) {
            $this->importDataWrapper = new KeyValue();
        }
        $this->importDataWrapper->setRawData($rawData);
        return $this;
    }

    public function getImportDataWrapper(): KeyValue
    {
        return $this->importDataWrapper;
    }

    public function setImportDataWrapper(KeyValue $importDataWrapper): self
    {
        $this->importDataWrapper = $importDataWrapper;
        return $this;
    }

}
