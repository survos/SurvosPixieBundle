<?php

namespace Survos\PixieBundle\Model;

class Table
{
    public function __construct(
        public string $name,
        public array $columns,
        public ?string $pkName=null,
    )
    {

    }

    public function getPkName(): ?string
    {
        return $this->pkName;
    }

//    public function setPkName(?string $pkName): void
//    {
//        $this->pkName = $pkName;
//    }

}
