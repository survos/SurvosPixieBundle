<?php

namespace Survos\PixieBundle\Model;

class ResizedImage
{
    public function __construct(
        public string|OriginalImage $imageKey, // the key to the image
        public ?string $size='thumb', // @todo: validate, thumb|orig
        public ?string $url=null, // e.g. wet, dry??
    )
    {
        if ($imageKey instanceof OriginalImage) {
            $this->imageKey = $this->imageKey->getKey();
        }
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
        $properties['key'] = $this->getKey();
        return $properties;

    }

    public function getKey(): string
    {
        return $this->size . '-' . $this->imageKey;
    }




}
