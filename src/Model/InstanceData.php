<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Model;

use App\Service\AppService;
use Survos\PixieBundle\Entity\Field\AttributeField;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\DatabaseField;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\PixieBundle\Entity\Field\MeasurementField;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\FieldMap;
use Survos\PixieBundle\Entity\Instance;

class InstanceData
{
    private string $identifier;
    private const DB_KEY = 'D',
        ATTR_KEY = 'A',
        CAT_KEY = 'C',
        RELATION_KEY = 'R',
        MEASURE_KEY = 'M',
        REF_KEY = 'f';

    public function __construct(

        private array $mdata = [],
        private array $data = [
//            'db' => [],
//            'cat' => [],
//            'uuid' => null,
//            'references' => [],
//            '_' => [],
//            'rel' => [],
        ]
    )
    {
    }

    public function setFieldValue(FieldInterface $field, mixed $value): self
    {

        $value = match($field::class) {
            DatabaseField::class => $value,
            RelationField::class,
            CategoryField::class => ['code' => FieldMap::slugify($value), 'label' => $value],
            AttributeField::class => // really depends on type
                $value,
            default => assert(false, 'missing ' . $field::class)
        };

        $this->mdata[$field->getCode()] = $value;
        if ($field::class == CategoryField::class) {
            dd($value, $this->mdata);
        }

        match($field::class) {
            DatabaseField::class => $this->setDbField($field->getInternalCode(), $value),
            CategoryField::class => $this->setCategory($field, $value),
            AttributeField::class => $this->addAttribute($field->getCode(), $value),
            RelationField::class => $this->addRelation($field, $value),
            default => assert(false, 'missing ' . $field::class)
        };
        return $this;
    }

    public function setDbField(string $dbField, $value)
    {

        if ($dbField == Instance::DB_DESCRIPTION_FIELD) {
//            dump($dbField, $value);
        }
        assert(in_array($dbField, Instance::DB_FIELDS), "$dbField is not a valid database field");
//        if ($dbField = Instance::DB_CODE_FIELD) assert(false, $dbField . ': ' . $value);

        // hack for penn, but hopefully applies everywhere.  Treat | as linefeeds, for title and description
        if ($value && str_contains($value, '|')) {
            $value = str_replace("|", "\n", $value);
        }

        $this->data[self::DB_KEY][$dbField] = $value;
        $this->mdata[$dbField] = $value;
    }

    /**
     * @return array
     */
    public function getDb(string $dbField): string|int|null
    {
        assert(in_array($dbField, Instance::DB_FIELDS), "$dbField is not a valid database field");
        return $this->data[self::DB_KEY][$dbField] ?? null;
    }

    public function getCat(string $catField): ?string
    {
        return $this->data[self::CAT_KEY][$catField] ?? null;
    }

    public function getRel(string $relField): ?array
    {
        return $this->data[self::RELATION_KEY][$relField] ?? null;
    }

    public function getReferences(): ?array
    {
        return $this->data[self::REF_KEY] ?? null;
    }

    public function getDbArray(): ?array
    {
        return $this->data[self::DB_KEY] ?? null;
    }

    public function setCategory(CategoryField $categoryField, $value)
    {
        $this->data[self::CAT_KEY][$categoryField->getCode()] = $value;
    }

    public function setMeasure(MeasurementField $measurementField, $value)
    {
        // @todo: units, dimensions, etc.
        $this->data[self::MEASURE_KEY][$measurementField->getCode()] = $value;

    }

    public function addRelation(RelationField $relationField, $value)
    {
        // use the actual code.
        $this->data[self::RELATION_KEY][$relationField->getCode()][] = $value;
    }

    public function addReference(array $reference) // eventually we'll need more than this.
    {
        $this->data[self::REF_KEY][] = $reference;
    }

    public function addAttribute(string $var, mixed $value)
    {
        $this->data[self::ATTR_KEY][$var] = $value;
    }

    public function addExtra(string $var, mixed $value)
    {
        $this->data['_'][$var] = $value;
    }

    public function addMultivalueAttribute(string $var, mixed $value)
    {
        $this->data[self::ATTR_KEY][$var][] = $value;
    }

    public function getmValue($code, bool $throwErrorIfMissing = false): mixed
    {
        if ($throwErrorIfMissing) {
            if (!array_key_exists($code, $this->mdata)) {
                dump($this->mdata, $code);
            }
            assert(array_key_exists($code, $this->mdata), "missing $code in \n" . join("\n", array_keys($this->mdata)));
        }
        return $this->mdata[$code] ?? null;
    }

    public function getCode(): string|int|null
    {
        return $this->getDb(Instance::DB_CODE_FIELD);
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     * @return InstanceData
     */
    public function setIdentifier(string $identifier): InstanceData
    {
        $this->identifier = $identifier;
        return $this;
    }


    public function setCode(string $code): self
    {
        assert(!str_contains($code, "\n"));
        $this->setDbField(Instance::DB_CODE_FIELD, $code);
        return $this;
    }

    public function getLabel(): string|int|null
    {
        return $this->getDb(Instance::DB_LABEL_FIELD);
    }

    public function getAttributes(): array
    {
        return $this->data[self::ATTR_KEY] ?? [];
    }

    public function getAttribute(string $code): mixed
    {
        return $this->getAttributes()[$code] ?? null;
    }

    public function getMeasure(string $code): mixed
    {
        return $this->getAttributes()[$code] ?? null;
    }

    public function setLabel(string $label): self
    {
        $this->setDbField(Instance::DB_LABEL_FIELD, AppService::trimmer($label));
        return $this;
    }

    public function getNormalizedData()
    {
        return $this->mdata;
        return $this->data;
    }
}
