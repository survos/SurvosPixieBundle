<?php

namespace Survos\PixieBundle\Model;

class Column
{
    public function __construct(
        public ?string $name=null,
        public string $type = 'TEXT',
        public string $label = '',
        public mixed $defaultValue = null,
        // here? or in Index?
        public bool $isPrimary = false,
        public bool $isUnique = false
    )
    {
        $this->type = strtoupper($this->type);
    }



}
