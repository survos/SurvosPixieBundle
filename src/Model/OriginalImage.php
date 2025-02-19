<?php

namespace Survos\PixieBundle\Model;

use App\Service\ImageService;
use Survos\SaisBundle\Service\SaisClientService;

class OriginalImage
{
    public ?string $key = null; // the key to the image, calculated if empty
    public function __construct(
        public string $imageUrl, // e.g. wet, dry??
        string $root,
    )
    {
        $isValid = filter_var($this->imageUrl, FILTER_VALIDATE_URL);
//        if (empty($this->key)) {
            $this->key = SaisClientService::calculateCode($this->imageUrl, $root);
//        }
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
        return $this->key;
    }




}
