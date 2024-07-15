<?php

namespace Survos\PixieBundle\Model;

class Item implements \Stringable
{
    public function __construct(
        private object $data,
        public ?string $key=null,
        public ?string $table=null,
        public ?string $pixieCode=null,// for workflow?
        private ?string $marking=null
    )
    {


    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function __toString(): string
    {
        return $this->getKey();
    }

    public function getMarking(): ?string
    {
        return $this->data->marking??null; // default to null or throw error?
//        return $this->data['marking'] ?? null;
    }

    public function setMarking(?string $marking): Item
    {
        $this->data->marking=$marking; // default to null or throw error?
        $this->marking = $marking;
//        dd($marking, $this);
//        $this->marking = $marking;
        return $this;
    }

    // this is more like the raw row.  Or value...
    public function getData(): object
    {
        return $this->data;
    }

    public function getRp(array $addl=[])
    {
        return array_merge($addl, [
            'key' => $this->key,
            'tableName' => $this->table,
            'pixieCode' => $this->pixieCode,
        ]);

    }

//    public function __get($name): mixed
//    {
//        return $this->data->{$name}??null; // default to null or throw error?
//    }
    public function __call(string $name, array $arguments=[]): mixed
    {
        if ($name == 'marking') {
//            dd($arguments, $this, $this->{$name}, $this->data);
        }
        return $this->data->{$name}??null; // default to null or throw error?
    }

}
