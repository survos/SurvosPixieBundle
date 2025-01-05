<?php

namespace Survos\PixieBundle\Model;

class Item implements \Stringable
{
    public function __construct(
        private object $data,
        public ?string $key=null,
        private readonly ?string $table=null,
        public ?string $pixieCode=null,// for workflow?
        private ?string $marking=null,
        private ?array $currentPlaces=[] // if not singleState
    )
    {


    }

    public function getCurrentPlaces(): ?array
    {
        return $this->currentPlaces;
    }

    public function setCurrentPlaces(?array $currentPlaces): Item
    {
        $this->currentPlaces = $currentPlaces;
        return $this;
    }

    public function getTableName(): ?string
    {
        return $this->table;
    }

    public function setData(object|array $data): self
    {
        if (is_array($data)) {
            $data = (object)$data;
        }
        $this->data = $data;
        return $this;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function __toString(): string
    {
        return (string) $this->getKey();
    }

    public function getMarking(): ?string
    {
        // hmm, why not $this->marking? messy, it's not derived from the json. If we know the workflow, we can only generate the correct column
        return $this->data->marking??$this->marking??null; // default to null or throw error?
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
    public function getData(bool $asArray=false): object|array
    {
        return $asArray ? json_decode(json_encode($this->data), true) : $this->data;
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
