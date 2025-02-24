<?php

namespace Survos\PixieBundle\Model;

use App\Service\ImageService;
use Survos\SaisBundle\Service\SaisClientService;

class OriginalImage
{
    public ?string $key = null; // the key to the image, calculated if empty
    public function __construct(
        public string $imageUrl, // e.g. wet, dry??
        public string $root,
        public ?array $context=[], // for context, to get back to the original record.  we could add context like label and description, too.  id or key at least
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
//        foreach ((new \ReflectionClass($this))->getProperties() as $property) {
//            if ($value = $this->{$property->getName()}) {
//                $properties[$property->getName()] = $value;
//            }
//        }
        // these must match the media.read serialization, they are overwritten during sync
        $properties['originalUrl'] = $this->imageUrl;
        $properties['key'] = $this->getKey();
        $properties['marking'] = 'new'; // really IMediaWorkflow::PLACE_NEW
        $properties['context'] = $this->context;
        return $properties;

    }

    public function getKey(): string
    {
        return $this->key;
    }

}
