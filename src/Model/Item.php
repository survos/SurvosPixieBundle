<?php

namespace Survos\PixieBundle\Model;

class Item
{
    public function __construct(
        public ?string $name=null,
        public ?string $marking=null, // for workflow
    )
    {

    }



}
