<?php

namespace Survos\PixieBundle\Model;

use App\Service\ImageService;

class OriginalImage
{
    public function __construct(
        public ?string $imageUrl=null, // e.g. wet, dry??
        public ?string $key = null, // the key to the image, calculated if empty
    )
    {
        if (empty($this->key)) {
            $this->key = ImageService::calculateHash($this->imageUrl);
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
        return $properties;

    }

    public function getKey(): string
    {
        return $this->key;
    }




}
