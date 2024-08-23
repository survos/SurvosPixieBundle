<?php

namespace Survos\PixieBundle\Model;

class Source
{
    public function __construct(
        public ?string $dir=null,
        public string $locale = 'en',
        public string $instructions = 'installation insructions',
        public null|string|array $ignore = [],
        public null|string|array $include = [],
        public array           $links=[],
    )
    {

    }

}
