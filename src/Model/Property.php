<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Model;

class Property implements \Stringable
{

    // since these are tied to grid-group, we can have relations and categories, though they are mostly used by Museado

    final public const TYPE_DATABASE = 'db'; // fixed properties, we need to pass it valid subtypes
    final public const TYPE_RELATION = 'rel'; // related to another grid
    final public const TYPE_CLASSIFICATION = 'cat'; // single relation within this grid, pass in valid subtypes
    final public const TYPE_LIST = 'list'; // single relation within this grid
    final public const TYPE_ATTRIBUTE = 'att';
    final public const TYPE_REFERENCE = 'ref'; // @todo: handle images and media.  Maybe json?

    // these are really attribute types only
    final public const PROPERTY_TEXT = 'textarea';
    final public const PROPERTY_STRING = 'string';
    final public const PROPERTY_INT = 'int';
    final public const PROPERTY_NUMERIC = 'num';
    final public const PROPERTY_BOOL = 'bool';
    final public const PROPERTY_DATE = 'date';
    final public const PROPERTY_ARRAY = 'array';

    final public const ATTRIBUTE_TYPES = [self::PROPERTY_INT, self::PROPERTY_TEXT, self::PROPERTY_STRING];

    final public const SETTING_MAP_POSITION = 'map_position';
    final public const SETTING_MAX = 'max';
    final public const SETTING_MIN = 'min';

    private ?int $orderIdx = null;


    public function __construct(
        private ?string $code = null,
        private ?string $type=null, // self::PROPERTY_STRING, // rel, cat, att
//        private ?string $rt=null, // related table, e.g. rel.per
        private ?string $subType = null, // e.g. type, relatedCore, dbField.  aka subType?
        private ?array $settings=[], // min/max, delimited, etc.
        private string|bool|null $index=null, // 'INDEX', 'UNIQUE' ??
        public bool $generated=true,
        private ?string $initial=null,
        private ?Schema $schema = null,

    )
    {
        if ($this->schema) {
            $schema->addProperty($this);
        }
    }

    public function getInitial(): ?string
    {
        return $this->initial;
    }

    public function setInitial(?string $initial): Property
    {
        $this->initial = $initial;
        return $this;
    }

    public function getIndex(): string|bool|null
    {
        return $this->index;
    }

    public function setIndex(?string $index): Property
    {
        $this->index = $index;
        return $this;
    }

    public function getOrderIdx(): ?int
    {
        return $this->orderIdx;
    }

    public function setOrderIdx(?int $orderIdx): self
    {
        $this->orderIdx = $orderIdx;
        return $this;
    }

    /**
     * @return Schema
     */
    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    /**
     * @param Schema $schema
     * @return Property
     */
    public function setSchema(Schema $schema): Property
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $code
     * @return Property
     */
    public function setCode(string $code): Property
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return Property
     */
    public function setType(string $type): Property
    {
        $this->type = $type;
        return $this;
    }

    public function isId(): bool
    {
        return $this->code == $this->schema->getIdName();
    }

    public function getRelatedTable(): ?Table
    {
        // https://github.com/simonw/til/blob/main/sqlite/related-rows-single-query.md
        if ($this->type == self::TYPE_RELATION) {
            dd($this);
            return $this->code == $this->schema->getIdName();
        }
        return null;
    }

    public function getListTableName(): ?string
    {
        // https://github.com/simonw/til/blob/main/sqlite/related-rows-single-query.md
        if ($this->type == self::TYPE_LIST) {
            return $this->getSubType() ?? $this->getCode();
        }
        return null;
    }

    // this should probably be at the field, not property, level, but this way we can translate before having fields.
    public function isTranslatable(): bool
    {
        return $this->getType() == Field::TYPE_INTRINSIC && in_array($this->getSubType(), Instance::TRANSLATABLE_FIELDS);
    }

    /**
     * @return string|null
     */
    public function getSubType(): ?string
    {
        return $this->subType;
    }

    /**
     * @param string|null $subType
     * @return Property
     */
    public function setSubType(?string $subType): Property
    {
        $this->subType = $subType;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function getSetting(string $key): ?string
    {
        return $this->settings[$key]??null;
    }

    public function getDelim(): ?string
    {
        return $this->getSettings()['delim']??null;
    }

    /**
     * @param array|null $settings
     * @return Property
     */
    public function setSettings(?array $settings): Property
    {
        $this->settings = $settings;
        return $this;
    }

    public function set(string $var, mixed $val): self
    {
        $this->settings[$var] = $val;
        return $this;
    }

    public function get($var, $default=null): mixed
    {
        return $this->settings[$var]??$default;
    }


    public function __toString(): string
    {
        $x = $this->getCode();
        $settings = $this->getSettings();
        if ($type = $this->getType()) {
            // if array, use the short form.
            if ($type === self::PROPERTY_ARRAY) {
                $x .= $settings['delim'];
                unset($settings['delim']);
            } else {
                // hack -- they type is wrong!
                if ($x <> $type) {
                    assert($x <> $type);
                    $x .= ':' . $type;
                }
            }
        }
        if ($subType = $this->getSubType()) {
            $x .= '.'.$subType;
        }

        $x .= match($this->index) {
            'PRIMARY' => '#',
            'INDEX' => '#',
            'UNIQUE' => '##',
            null => '',
            default => dd($this->index)
        };
        if ( $settings && !empty($settings)) {
            $x .= '?' . http_build_query($settings);
        }

        return $x;

    }
}

