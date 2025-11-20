<?php

namespace Survos\PixieBundle\Model;

class Table
{
    /**
     * @param array<Column> $columns
     * @param array<Property|string> $properties
     * @param array<string> $extras
     * @param array<string> $uses
     * @param array<string> $translatable
     */
    public function __construct(
        public ?string $name = null,
        // keyed by regex
        public array $rules = [],
        /** @var array<Column> */ public array $columns = [],
        /** @var array<Property|string> */ public array $properties = [],
        public readonly array $patches = [],
        /** @var array<string> */ public readonly array $extras = [],
        /** @var array<string> */ public readonly array $uses = [],
        /** @var array<string> */ public readonly array $translatable = [],
        public ?string $indexes = null,     // dexie-style: "id|int,department"
        public ?string $pkName = null,
        public readonly ?string $extends = null,
        public ?string $workflow = null,
        public ?bool $has_images = null,
        public ?int $total = null,
        public readonly ?string $parent = null,
    ) {}

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
