<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Model;

class Schema
{

    public function __construct(
        private string $idName = 'id',
        private array $properties = [],
        private array $valueRules = []
    )
    {
    }

    /**
     * @return array
     */
    public function getValueRules(): array
    {
        return $this->valueRules;
    }

    /**
     * @param array $valueRules
     * @return Schema
     */
    public function setValueRules(array $valueRules): Schema
    {
        $this->valueRules = $valueRules;
        return $this;
    }

    /**
     * @return string
     */
    public function getIdName(): string
    {
        return $this->idName;
    }

    /**
     * @param string $idName
     * @return Schema
     */
    public function setIdName(string $idName): Schema
    {
        $this->idName = $idName;
        return $this;
    }


    /**
     * @return array<string, Property>
     */
    public function getKeyedProperties(): array
    {
        return $this->properties;
    }

    /**
     * @return Property[]
     */
    public function getSortedProperties(): array
    {
        // push the null columns to the end
        $sorted = [];
        foreach ($this->getKeyedProperties() as $property) {
            if (is_null($property->getOrderIdx())) {
                $property->setOrderIdx(9999);
            }
            $sorted[] = $property;
        }
        usort($sorted, fn(Property $x, Property $y) => $x->getOrderIdx() <=> $y->getOrderIdx());
        assert(array_is_list($sorted));
        return $sorted;
    }

    public function getPropertyCodes(): array
    {
//        return $this->getSortedPropertyCodes();
        return array_keys($this->getKeyedProperties());
    }

    public function getPropertyCount(): int
    {
        return count($this->properties);
    }
    public function getSortedPropertyCodes(): array
    {
        $sortedProperties = [];
//        dd($this->getKeyedProperties(), $this->getSortedProperties());
        foreach ($this->getSortedProperties() as $property) {
            $sortedProperties[] = $property->getCode();
        }
        return $sortedProperties;
//        dd($sorted, $sortedProperties);
        return array_keys($this->getProperties());
    }

    /**
     * @param array $properties
     * @return Schema
     */
    public function setProperties(array $properties): Schema
    {
        $this->properties = $properties;
        return $this;
    }

    public function addProperty(Property $property): void {
        $this->properties[$property->getCode()] = $property;
    }

    public function getProperty(string $code): ?Property
    {
        return $this->properties[$code]??null;
    }


}

