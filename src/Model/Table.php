<?php

namespace Survos\PixieBundle\Model;

class Table
{
    public function __construct(
        private ?string $name=null,
        // keyed by regex
        private array $rules=[],
        /**
         * @var array<Column>
         */
        private array $columns=[],
        /**
         * @var array<Property|string>
         */
        private array   $properties=[],
        private readonly array   $patches=[],
        /** @var array<string> */ private readonly array   $extras=[],
        /** @var array<string> */ private readonly array   $uses=[],
        /** @var array<string> */  private readonly array   $translatable =[],
        private ?string $indexes=null, // the super-succint dexie-style index defintion, e.g. "id|int,department"
        private ?string $pkName=null,
        private readonly ?string $extends=null,
        private ?string $workflow=null,
        private ?bool   $has_images=null,
        private ?int    $total=null, // if known, speeds up count, especially JSON
        private readonly ?string $parent=null, // one ManyToOne, e.g. Artwork or Objekt in the 'image' table
    )
    {
    }

    public function getExtras(): array
    {
        return $this->extras;
    }

    public function getUses(): array
    {
        return $this->uses;
    }


    public function getExtends(): ?string
    {
        return $this->extends;
    }

    public function getHasImages(): ?bool
    {
        return $this->has_images;
    }

    public function getParent(): ?string
    {
        return $this->parent;
    }

    public function isHasImages(): bool
    {
        if (is_null($this->has_images)) {
            $this->has_images = $this->name == 'obj';  // hack!
        }
        return $this->has_images;
    }

    public function getPatches(): array
    {
        return $this->patches;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(?int $total): Table
    {
        $this->total = $total;
        return $this;
    }

    public function getTranslatable(): array
    {
        return $this->translatable;
    }

    public function getWorkflow(): ?string
    {
        return $this->workflow;
    }

    public function setWorkflow(?string $workflow): Table
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getIndexes(): ?string
    {
        return $this->indexes;
    }

    public function setIndexes(?string $indexes): Table
    {
        $this->indexes = $indexes;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): Table
    {
        $this->name = $name;
        return $this;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function setRules(array $rules): Table
    {
        $this->rules = $rules;
        return $this;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function setColumns(array $columns): Table
    {
        $this->columns = $columns;
        return $this;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getPropertiesByCode(): array
    {
        $properties = [];
        foreach ($this->getProperties() as $property) {
            $properties[$property->getCode()] = $property;
        }
        return $properties;
    }


    public function setProperties(array $properties): Table
    {
        $this->properties = $properties;
        return $this;
    }

    public function getPkName(): ?string
    {
        return $this->pkName;
    }

    public function setPkName(?string $pkName): Table
    {
        $this->pkName = $pkName;
        return $this;
    }

    public function isObj(): bool
    {
        return $this->isTable('obj');
    }

    public function isTable(string $tableName): bool
    {
        return $this->getName() === $tableName;
    }

    public function getJsonFields(): array
    {
        return array_filter($this->getProperties(), fn(Property $property) => $property->getType() === 'json');

    }
}
