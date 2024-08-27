<?php

namespace Survos\PixieBundle\Model;

class Source
{
    public function __construct(
        public ?string $dir=null,
        public string $label = '',
        public string $description = 'source description',
        public string $locale = 'en',
        public string $country = 'us',
        public string $instructions = 'installation insructions',
        public null|string|array $ignore = [],
        public null|string|array $include = [],
        public array           $links=[],
    )
    {
        $this->instructions = trim($this->instructions);

    }

}
