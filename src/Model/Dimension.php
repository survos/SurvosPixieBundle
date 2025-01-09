<?php

namespace Survos\PixieBundle\Model;

class Dimension
{
    public function __construct(
        public ?string $name=null, // e.g. unframed, framed, common
        public ?string $shape=null, // box, rect, cylinder, circle
        public ?string $units='cm', // cm or in, etc.
        public ?float $width=null,
        public ?float $height=null,
        public ?float $depth=null,
        public ?float $length=null,
        public ?float $diameter=null,
        public ?float $thickness=null,
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
