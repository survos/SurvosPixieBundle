<?php

namespace Survos\PixieBundle\Model;

class Weight
{
    public function __construct(
        public ?float $amount,
        public ?string $units='g', // oz, lbs
        public ?string $name=null, // e.g. wet, dry??
    )
    {
    }

    public function asArray(): array
    {
        $properties = [];
        // go through each property and return it if it's set
        foreach ((new \ReflectionClass($this))->getProperties() as $property) {
            if ($value = $this->{$property->getName()}) {
                $properties[$property->getName()] = $value;
            }
        }
        return $properties;

    }




}
