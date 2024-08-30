<?php

namespace Survos\PixieBundle\Model;

class Dimension
{
    public function __construct(
        public ?string $name=null, // e.g. unframed, framed, common
        public ?string $type=null, // box, rect, cylinder, circle
        public ?string $units, // cm or in, etc.
        public ?float $width,
        public ?float $height,
        public ?float $depth,
    )
    {
    }



}
