<?php

namespace Survos\PixieBundle\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Serializer\Annotation\Groups;

trait StatsTrait
{
//    use UuidAttributeTrait;

    // not persisted, used during analysis to count
    private array $columnValues = [];

    #[ORM\Column(type: Types::JSON, options: [
        'jsonb' => true,
    ], nullable: true)]
    #[Groups(['fieldMap.read'])]
    private array $valueCounts = [];

    #[ORM\Column(nullable: true)]
    #[Groups(['fieldMap.read'])]
    private ?int $distinctValuesCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $fillCount;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    #[Groups(['fieldMap.read'])]
    private ?string $dataType = null;

    /**
     * @param string|null $dataType
     * @return StatsTrait
     */
    public function setDataType(?string $dataType): self
    {
        $this->dataType = $dataType;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFillCount()
    {
        return $this->fillCount;
    }

    /**
     * @param mixed $fillCount
     * @return StatsTrait
     */
    public function setFillCount($fillCount)
    {
        $this->fillCount = $fillCount;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function setDoctrineDataType(?string $dataType): self
    {
        $this->dataType = $dataType;
        return $this;
    }


    public function resetValuesCount(): void
    {
        $this->valueCounts = [];
        $this->columnValues = [];
    }

    public function getColumnValues(): array
    {
        return $this->columnValues;
    }

    public function setColumnValues(array $columnValues): self
    {
        $this->columnValues = $columnValues;

        return $this;
    }

    public function addValue($value)
    {
        assert(!str_contains($value, '|'), "$value should not contain a |");
//        assert(!str_contains($value, '|'), "pipe found! " . $value);
        if (! is_null($value) && ! ($value === '')) {
            if ($this->getCode() == 'excel_row') {
//                dump($this->getCode(), $value, $this->getColumnValues());
//                assert(!in_array($value, $this->columnValues));
            }

            $value = trim($value, '.');
            $value = trim($value); // spaces
            array_push($this->columnValues, $value);
        }
    }

    public function incValueCount($value, $count=1)
    {
        assert(!str_contains($value, '|'), "$value should not contain a |");
//        assert(!str_contains($value, '|'), "pipe found! " . $value);
        if (!array_key_exists($value, $this->valueCounts)) {
            $this->valueCounts[$value] = 0;
        }
        $this->valueCounts[$value] += $count;
        $this->addTypeAttributes($value);
        $this->setDistinctValuesCount(count($this->valueCounts));
    }



    // return true if it's the first time the attribute has been seen
    private function initAttribute(string $attribtue, mixed $value): mixed
    {
        if (!$this->has($attribtue)) {
            $this->set($attribtue, $value);
        }
        return $this->get($attribtue);
    }
    private function addTypeAttributes(int|string $value)
    {
        $type = gettype($value);
//        "boolean", "integer", "double", "string", "array", "object", "resource", "NULL", "unknown type", "resource (closed)"

        foreach (['min_int','max_int','max_str_length'] as $attributeKey) {
            $newValue = match($attributeKey) {
                'min_int' => is_int($value) ? min($value, $this->initAttribute($attributeKey, $value)): null,
                'max_int' => is_int($value) ? max($value, $this->initAttribute($attributeKey, $value)): null,
                'max_str_length' => is_string($value) ? max($this->initAttribute($attributeKey, strlen($value)), strlen($value)) : null,
            };
            if (!is_null($newValue)) {
                $this->set($attributeKey, $newValue);
            }
        }
    }

    public function addColumnValues(array $moreColumnValues)
    {
        $this->columnValues += $moreColumnValues;
    }

    public function getValueCounts(bool $sorted = false): array
    {
        // objects do no preserve their order when stored as json
        if ($sorted) {
            arsort($this->valueCounts);
        }
        return $this->valueCounts;
    }

    #[Groups(['fieldMap.read'])]
    public function getCodedValueCounts(): array
    {
        // hackish,
        $codedValueCounts = [];
        foreach ($this->getValueCounts() as $value => $count) {
//            $codedValueCounts[FieldMap::slugify($value)] = $count;
        }
        return $codedValueCounts;

    }

    public function setValueCounts(array $valueCounts): self
    {
        $this->valueCounts = $valueCounts;
        $this->setDistinctValuesCount(count($valueCounts));
        return $this;
    }

    public function getDistinctValuesCount(): ?int
    {
        return $this->distinctValuesCount;
    }

    public function setDistinctValuesCount(?int $distinctValuesCount): self
    {
        $this->distinctValuesCount = $distinctValuesCount;

        return $this;
    }


    #[Groups(['spreadsheet'])]
    public function isUniqueValues(): bool
    {
        if (count($this->getValueCounts())) {
            //            dd($this->getStats(), array_values($this->getStats()));
            return max(array_values($this->getValueCounts())) == 1;
        } else {
            return false;
        }
    }

    public function processValueCounts(): void
    {
        $columnData = $this->getColumnValues();
        // get the summary of the types.
        $types = $this->array_count_types($columnData);
        if ((is_countable($types) ? count($types) : 0) == 1) {
            $type = key($types); // get the first type , instead of $types[0]
            //                    $fieldMap->setType($type);
            switch ($type) {
                case 'NULL':
                    $data = [];
                    break;
                case 'object':
                    break;
                case 'integer':
                case 'string':
                    $data = array_count_values($columnData);
                    break;
                case 'boolean':
                    //                        $fieldConfig->setFieldType(FieldConfig::FIELD_BOOL);
                    $data = [
                        'true' => 0,
                        'false' => 0,
                    ];
                    array_walk($columnData, fn($x) => $x ? $data['true']++ : $data['false']++);
                    dd($data);
                    break;
                default:
                    throw new \Exception("$type not handled yet");
            }
            $this->setValueCounts($data);
            $this->setDoctrineDataType($type);
        } else {
//            return;
            if (count($columnData)) {
                if (array_keys($types) == ['integer', 'string']) {
                    $data = array_count_values($columnData);
                    dd($data, $types);
                } else {
                    dd($types, array_keys($types));
                    assert(false, "@todo: handle things like year with both integers and strings, make them all strings");
                }
                //                        dd($fieldMap->getCode(), $types, $columnData, $fieldMap);
            }
        }

    }

    /**
     * @return array<string, int>
     */
    private function array_count_types($array): array
    {
        $types = [];
        foreach ($array as $element) {
            $type = gettype($element);
            if (! array_key_exists($type, $types)) {
                $types[$type] = 0;
            }
            $types[$type]++;
        }
        return $types;
    }

    public function getOptions(): array
    {
        return (new OptionsResolver())
        ->setDefaults([
            'min' => null,
            'max' => null
        ])
            ->resolve($this->all());

    }


}
