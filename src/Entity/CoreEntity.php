<?php

// base class for all Cores (Obj, StorageLocation, etc.)

namespace Survos\PixieBundle\Entity;

use App\Entity\ProjectCoreInterface;
use App\Traits\ProjectCoreTrait;
use Survos\CoreBundle\Entity\RouteParametersInterface;

class CoreEntity implements RouteParametersInterface, ProjectCoreInterface, \Stringable
{
    //    use RouteParametersTrait;
    use ProjectCoreTrait;

    public function setTags(array $tags): self
    {
        return $this;
    }

    public function __construct()
    {
        $this->initId();
        //        if (!isset($this->id)) {
        //            $this->id = new Ulid();
        //        }
        //        assert($this->id, "missing id in construct");
        //        $this->relations = new ArrayCollection();
    }

    public function getDependencies()
    {
    }

    public function getRelatedClasses()
    {
        return match (self::class) {
            default => [],
        };
    }

    public function privateProperties()
    {
        $reflection = new \ReflectionClass($this);
        return $reflection->getProperties();
    }

    public function getClass(): string
    {
        {
            return self::class;
        }
    }

    public function __toString()
    {
        return $this->getCode();
    }
}
