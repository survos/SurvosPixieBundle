<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\AccessInterface;
use App\Entity\IdInterface;
use App\Entity\LabelInterface;
use App\Entity\OldCore;
use App\Entity\Sheet;
use App\Repository\CoreRepository;
use App\Service\AppService;
use App\Traits\AccessTrait;
use Survos\PixieBundle\Traits\IdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Deprecated;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Entity\Field\AttributeField;
use Survos\PixieBundle\Entity\Field\CategoryField;
use Survos\PixieBundle\Entity\Field\DatabaseField;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Entity\Field\FieldInterface;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CoreRepository::class)]
#[ORM\UniqueConstraint(name: 'core_unique', columns: ['code'])]
#[UniqueEntity(['code'])]
#[ApiResource(operations: [new Get(), new Put(), new Delete(), new Patch(),
    new GetCollection()], shortName: 'projectCores',
    denormalizationContext: ['groups' => ['projectCore.write']],
    normalizationContext: ['groups' => ['core.read', 'rp']])]
class Core implements CoreInterface, RouteParametersInterface,
//    AccessInterface,
    \Stringable, MarkingInterface
{
//    use CodeIdentifierTrait,
    use
        IdTrait,
        MarkingTrait,
//        InstanceTrait,
//        AccessTrait,
        RouteParametersTrait;

    public const array UNIQUE_PARAMETERS = ['tableName' => 'code'];

//    #[ORM\Column(type: 'string', length: 255, nullable: false)]
//    #[Assert\Length(max: 255, maxMessage: 'The code cannot be longer than {{ limit }} characters')]
    #[Groups(['core.read'])]
    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    #[Assert\Length(max: 255, maxMessage: 'The code cannot be longer than {{ limit }} characters')]
    protected string $code;


    #[Groups(['pc.export'])]
    public function getCoreCode(): string
    {
        return $this->coreCode;
    }

    public function initNonPersisted(): self
    {
//        $this->spreadsheets = new ArrayCollection();
        $this->sheets = new ArrayCollection();
        $this->fieldMaps = new ArrayCollection();
        return $this;
    }

//    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'cores', cascade: ['persist'])]
//    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Deprecated]
    protected Project $project;

    #[Groups(['core.read', 'dashboard', 'related', 'write', 'pc.export'])]
    #[ORM\Column(type: Types::STRING, nullable: true, length: 8)]
    private string $coreCode;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['core.read', 'dashboard', 'related', 'write', 'pc.export'])]
    private $isEnabled;
    #[ORM\OneToMany(targetEntity: FieldSet::class, mappedBy: 'core', indexBy: 'code', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['orderIdx' => 'ASC'])]
    protected $fieldSets;

    #[ORM\Column(type: Types::INTEGER, nullable: false)]
    #[Groups(['core.read', 'dashboard', 'related', 'write', 'pc.export'])]
    private int $instanceCount = 0;
    #[Groups(['read', 'dashboard'])]
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private $relatedCounts;
    #[Groups(['read', 'dashboard'])]
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private $typeBreakdown = [];
    #[ORM\OneToMany(targetEntity: CustomField::class, mappedBy: 'core', orphanRemoval: true, cascade: ['persist'])]
    private $customFields;

    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'core', indexBy: 'code', orphanRemoval: true, cascade: ['persist'], fetch: 'EAGER')]
    #[ORM\OrderBy(['orderIdx' => 'ASC'])]
    protected Collection $fields;
    #[ORM\OneToMany(targetEntity: InstanceTextType::class, mappedBy: 'core', orphanRemoval: true, cascade: ['persist'])]
    private $instanceTextTypes;

//    #[ORM\OneToMany(mappedBy: 'projectCore', targetEntity: FieldMap::class, indexBy: 'code', cascade: ['persist'], orphanRemoval: true)]
    private ArrayCollection $fieldMaps;
//    #[ORM\OneToMany(mappedBy: 'relationship', targetEntity: FieldMap::class)]
//    private $relationshipFieldMaps;

    #[ORM\OneToMany(mappedBy: 'core', targetEntity: Reference::class, orphanRemoval: true)]
    private $references;
    #[ORM\Column]
    private int $referenceCount = 0;

    #[ORM\OneToMany(mappedBy: "core", targetEntity: Category::class, cascade: ["persist"], orphanRemoval: true)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['read', 'related', 'write'])]
    private Collection $categories;

    const CONFIG_TYPES = ['type', 'source', 'label', 'workflow', 'status', 'deaccession', 'accession', 'list'];

    #[ORM\Column]
    private array $configSummary;

    #[ORM\OneToMany(mappedBy: 'core', targetEntity: Instance::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $instances;

    #[ORM\OneToMany(mappedBy: 'core', targetEntity: Relation::class, orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: false, name: 'xxx')]
    private Collection $relations;

//    #[ORM\OneToMany(mappedBy: 'projectCore', targetEntity: Sheet::class, orphanRemoval: true)]
    private ArrayCollection $sheets;

    // the Spreadsheet fields
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $fieldCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $fieldLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $fieldDescription = null;

    #[Gedmo\SortablePosition]
    #[ORM\Column(nullable: true)]
    private ?int $orderIdx = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idFieldCode = null;

    #[ORM\Column(nullable: true)]
    private ?array $schema = [];

    #[ORM\Column(nullable: true)]
    private ?int $meiliCount = null;

    /**
     * @var Collection<int, Row>
     */
    #[ORM\OneToMany(targetEntity: Row::class, mappedBy: 'core', orphanRemoval: true)]
    private Collection $rows;

    #[ORM\ManyToOne(inversedBy: 'cores')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Owner $owner = null;

    #[ORM\Column]
    private int $rowCount = 0;


    /**
     * @return string|null
     */
    public function getFieldCode(): ?string
    {
        return $this->fieldCode;
    }

    /**
     * @param string|null $fieldCode
     * @return Core
     */
    public function setFieldCode(?string $fieldCode): Core
    {
        $this->fieldCode = $fieldCode;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFieldLabel(): ?string
    {
        return $this->fieldLabel;
    }

    /**
     * @param string|null $fieldLabel
     * @return Core
     */
    public function setFieldLabel(?string $fieldLabel): Core
    {
        $this->fieldLabel = $fieldLabel;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFieldDescription(): ?string
    {
        return $this->fieldDescription;
    }

    /**
     * @param string|null $fieldDescription
     * @return Core
     */
    public function setFieldDescription(?string $fieldDescription): Core
    {
        $this->fieldDescription = $fieldDescription;
        return $this;
    }


    /**
     * @return Collection
     */
    public function getModuleConfig(): Collection
    {
        return $this->categories;
    }

    /**
     * @param Collection $moduleConfig
     * @return Core
     */
    public function setCategories(Collection $moduleConfig): Core
    {
        $this->categories = $moduleConfig;
        return $this;
    }

    public function __construct(
        ?string $code=null,
        ?Owner $owner=null,
    )
    {
        if ($owner) {
            $owner->addCore($this);
        }
        assert($code);
            $this->initId($code);
//        $this->coreDictionary = $dictionaries['core_icons'];
        $this->coreCode = $code; // needed for adding project core

        $this->configSummary = []; // was array_fill_keys(self::CONFIG_TYPES, 0);
        $this->categories = new ArrayCollection();
        $this->fieldSets = new ArrayCollection();
        $this->relatedCounts = [];
        $this->customFields = new ArrayCollection();
        $this->fields = new ArrayCollection();
        $this->instanceTextTypes = new ArrayCollection();
        $this->fieldMaps = new ArrayCollection();

        $this->references = new ArrayCollection();
        $this->referenceCount = 0;

        $this->instances = new ArrayCollection();

        $this->relations = new ArrayCollection();
        $this->sheets = new ArrayCollection();
        $this->rows = new ArrayCollection();
    }

//    public function getCore(): ?OldCore
//    {
//        assert(false, "use coreCode?");
//        return $this->core;
//    }


    public function getIcon()
    {
        return 'fas fa-bug fa-2x';
        assert(false);
        return $this->coreDictionary[$this->getCoreCode()] ?? 'fal fa-bug';
    }

    public function getIsEnabled(): ?bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(?bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    /**
     * @return Collection|FieldSet[]
     */
    public function getFieldSets(): Collection
    {
        return $this->fieldSets;
    }

    public function addFieldSet(FieldSet $fieldSet): self
    {
        if (!$this->fieldSets->contains($fieldSet)) {
            $this->fieldSets[$fieldSet->getCode()] = $fieldSet;
            $fieldSet->setCore($this);
        }

        return $this;
    }

    public function getFieldSetByCode(string $code, $autoCreate = true): ?FieldSet
    {
        if (!$fieldSet = $this->fieldSets[$code] ?? null) {
            if ($autoCreate) {
                $fieldSet = new FieldSet($code, $this);
            }
        }
        return $fieldSet;
    }

    public function getFieldsByClass(string $fieldClass): Collection
    {
        // maybe better to do this in the repo?
        // also, might be proxy, so use actualClass
        return $this->getFields()->filter(fn(Field $field) => $fieldClass == AppService::actualClass($field::class));
    }

    /**
     * @return Collection|RelationField[]
     */
    public function getRelationFields(): Collection
    {
        return $this->getFieldsByClass(RelationField::class);
    }

    /**
     * @return Collection|AttributeField[]
     */
    public function getAttributeFields(): Collection
    {
        return $this->getFieldsByClass(AttributeField::class);
    }

    /**
     * @return Collection|DatabaseField[]
     */
    public function getDatabaseFields(): Collection
    {
        return $this->getFieldsByClass(DatabaseField::class);
    }

    /**
     * @return Collection|CategoryField[]
     */
    public function getCategoryFields(): Collection
    {
        return $this->getFieldsByClass(CategoryField::class);
    }

    public function removeFieldSet(FieldSet $fieldSet): self
    {
        if ($this->fieldSets->removeElement($fieldSet)) {
            // set the owning side to null (unless already changed)
            if ($fieldSet->getCore() === $this) {
                $fieldSet->setCore(null);
            }
        }

        return $this;
    }

    public function getUniqueIdentifiers(): array
    {
        return ['coreId' => $this->getCoreCode()];
    }

    /**
     * @return Collection|RelationField[]
     */
    public function getLeftRelationFields(): Collection
    {
        return $this->getRelationFields();
    }

    public function addLeftRelationField(RelationField $leftRelation): self
    {
        if (!$this->leftRelationFields->contains($leftRelation)) {
            $this->leftRelationFields[] = $leftRelation;
            $leftRelation->setCore($this);
        }

        return $this;
    }

    public function removeLeftRelation(RelationField $leftRelation): self
    {
        if ($this->leftRelationFields->removeElement($leftRelation)) {
            // set the owning side to null (unless already changed)
            if ($leftRelation->getLeftCore() === $this) {
                $leftRelation->setCore(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|RelationField[]
     */
    public function getRightRelationFields(): Collection
    {
        return $this->rightRelationFields;
    }

    public function addRightRelationField(RelationField $rightRelation): self
    {
        if (!$this->rightRelationFields->contains($rightRelation)) {
            $this->rightRelationFields[] = $rightRelation;
            $rightRelation->setRightCore($this);
        }

        return $this;
    }

    public function removeRightRelation(RelationField $rightRelation): self
    {
        if ($this->rightRelationFields->removeElement($rightRelation)) {
            // set the owning side to null (unless already changed)
            if ($rightRelation->getRightCore() === $this) {
                $rightRelation->setRightCore(null);
            }
        }

        return $this;
    }

    public function getInstanceCount(): ?int
    {
        return $this->instanceCount;
    }

//    public function setInstanceCount(?int $instanceCount): self
//    {
//        $this->instanceCount = $instanceCount;
//        return $this;
//    }

    public function getRelatedCounts(): ?array
    {
        return $this->relatedCounts;
    }

    public function setRelatedCounts(?array $relatedCounts): self
    {
        $this->relatedCounts = $relatedCounts;

        return $this;
    }

    public function getTypeBreakdown(): ?array
    {
        return $this->typeBreakdown;
    }

    public function setTypeBreakdown(?array $typeBreakdown): self
    {
        $this->typeBreakdown = $typeBreakdown;

        return $this;
    }

    public function incrementInstanceCount($inc = 1): self
    {
        $this->instanceCount += $inc;
        return $this;
    }

    public function updateRelatedCounts(IdInterface $typeEntity): self
    {
        $key = (string)$typeEntity->getId();
        $classKey = (new \ReflectionClass($typeEntity))->getShortName();
        if (!array_key_exists($classKey, $this->typeBreakdown)) {
            $this->typeBreakdown[$classKey] = [];
        }
        if (!array_key_exists($key, $this->typeBreakdown[$classKey])) {
            $this->typeBreakdown[$classKey][$key] = 0;
        }
        $this->typeBreakdown[$classKey][$key]++;
        $this->relatedCounts[$classKey] = is_countable($this->typeBreakdown[$classKey]) ? count($this->typeBreakdown[$classKey]) : 0;
        return $this;
    }

    public function getChartData(string $pluralCamel, string $key): array
    {
        $accessor = new PropertyAccessor();
        $labels = $values = [];

        // we could also put this in a service and call the querybuilder if we need a translation hint.
        /** @var LabelInterface|IdInterface $item */
        try {
            foreach ($accessor->getValue($this->getProject(), $pluralCamel) as $item) {
                $id = (string)$item->getId();
                if (array_key_exists($id, $this->typeBreakdown[$key])) {
                    array_push($labels, (string)$item->getLabel());
                    array_push($values, $this->getTypeBreakdown()[$key][$id]);
                }
            }
        } catch (\Exception) {
            // we need to handle the type names corrrectly.
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return Collection|CustomField[]
     */
    public function getCustomFields(): Collection
    {
        return $this->customFields;
    }

    public function addCustomField(CustomField $customField): self
    {
        if (!$this->customFields->contains($customField)) {
            $this->customFields[] = $customField;
            $customField->setCore($this);
            $customField->setProject($this->getProject());
        }

        return $this;
    }

    public function removeCustomField(CustomField $customField): self
    {
        if ($this->customFields->removeElement($customField)) {
            // set the owning side to null (unless already changed)
            if ($customField->getCore() === $this) {
                $customField->setCore(null);
            }
        }

        return $this;
    }

    public function setCoreCode(string $coreCode): self
    {
        $this->coreCode = $coreCode;

        return $this;
    }

    public function getCustomFieldByName($name): ?CustomField
    {
        static $cache;
        assert(false, " is the cache causing problems?");
        if (empty($cache[$this->getCoreCode()])) {
            foreach ($this->getCustomFields() as $customField) {
                $cache[$this->getCoreCode()][$customField->getCustomFieldType()->getName()] = $customField;
            }
        }
        // alas, there appears to be some bad data, like examination_condition
//        assert(array_key_exists($name, $cache), $name . ' not found in ' . json_encode(array_keys($cache)));
        return $cache[$this->getCoreCode()][$name] ?? null;
    }

    /**
     * @return Collection|Field[]
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function getFieldCodes(): array
    {
        $headers = [];
        foreach ($this->getFields() as $field) {
            $headers[] = $field->getCode();
        }
        return $headers;

        return $this->getFields()->map(fn(Field $field) => $field->getCode())->toArray();
    }


//    public function getFieldByCode(string $code): ?Field
//    {
//        return $this->getFields()->filter(fn(Field $pcf) => $pcf->getCode() === $code)->first() ?: null;
//    }

    public function getRelationFieldByCode(string $code): ?RelationField
    {
        return $this->getFieldByCode($code);
    }

    public function getCategoryFieldByCode(string $code, bool $throwErrorIfMissing = false): ?CategoryField
    {
        if ($field = $this->getFieldByCode($code, throwErrorIfMissing: $throwErrorIfMissing)) {
            assert($field->isCategory(), sprintf("$code is not a category field, it is %s [%s]", $field->getShortClass(), $field->getCode()));
        }
        return $field;

    }

    public function getFieldByName(string $name): ?FieldInterface
    {
        return $this->getFields()->filter(fn(Field $field) => ($field->getLabel() == $name) || (in_array($name, $field->getAliases())))->first() ?: null;
    }

    public function getFieldByInternalCode(?string $code): ?FieldInterface
    {
        return $this->getFields()->filter(fn(Field $field) => $field->getInternalCode() == $code)->first() ?: null;
    }

    public function getDatabaseFieldByInternalCode(string $code): ?DatabaseField
    {
        return $this->getDatabaseFields()->filter(fn(Field $field) => $field->getInternalCode() == $code)->first() ?: null;
    }

    // can't auto-create without FieldSet
    public function getFieldByCode($code, ?string $class = null, bool $autoCreate = false, bool $throwErrorIfMissing = true, array $fieldDef = []): FieldInterface|DatabaseField|CategoryField|RelationField|null
    {
        if ($class) {
            $autoCreate = true;
        }
        if ($code == 'row') {
//            dd($this->fields->getKeys());
        }
        $field = $this->fields[$code];
        if (!$field) {
            if ($autoCreate) {
                assert(in_array($class, Field::FIELD_CLASSES), "missing " . $class);
                $rightCoreCode = $fieldDef['core'] ?? null;

                /** @var Field $field */
                $field = match ($class) {
                    RelationField::class => new RelationField($code, $fieldDef['rrel'], $this, $this->getProject()->getProjectCore($rightCoreCode)),
                    default => (new $class($code, $this))->setCode($code)
                };
                if ($class == RelationField::class) {
                    dd($field, $code, $field->__toString());
                }
                if (in_array($class, [DatabaseField::class, CategoryField::class])) {
                    assert(array_key_exists('internalCode', $fieldDef), 'set internalCode in fieldDef');
                    $field->setInternalCode($fieldDef['internalCode']);
                }
//                $field->setCode($this); // set it, but don't add it.
//                assert(false, "Warning: creating field $code during get");
                $this->addField($field);
            } elseif
            ($throwErrorIfMissing) {
                assert(false, sprintf("Missing $code  $class in projectCore %s fields\n%s",
                    $this->getCoreCode(),
                    join("\n", array_keys($this->fields->toArray()))
                ));
            }
        }
        return $field;


    }

    public
    function getFieldByCategoryType(string $categoryType, bool $throwErrorIfMissing = true): ?CategoryField
    {
        // maybe getCategoryFields, to avoid bad code configuration?
        return $this->getFields()->filter(fn(Field $field) => $field->getInternalCode() === $categoryType)->first() ?: null;
    }

    public
    function addField(FieldInterface $field): self
    {
//        assert($field->getCode() <> 'materials');
        if (!$this->fields->contains($field)) {
            $this->fields[$field->getCode()] = $field;
            if (!$field->getOrderIdx()) {
                $field->setOrderIdx($this->getFields()->count());
            }

            $field->setCore($this);
//            $this->getProject()->addField($field);
        }
        if (($field::class == DatabaseField::class) && ($field->getInternalCode() == Instance::DB_CODE_FIELD)) {
            $this->setIdFieldCode($field->getCode());
        }

        return $this;
    }

    public function removeField(Field $field): self
    {
        if ($this->fields->removeElement($field)) {
            // set the owning side to null (unless already changed)
            if ($field->getCore() === $this) {
//                $field->setProjectCore(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|InstanceTextType[]
     */
    public
    function getInstanceTextTypes(): Collection
    {
        return $this->instanceTextTypes;
    }

    public
    function addInstanceTextType(InstanceTextType $instanceTextType): self
    {
        if (!$this->instanceTextTypes->contains($instanceTextType)) {
            $this->instanceTextTypes[] = $instanceTextType;
            $instanceTextType->setCore($this);
        }

        return $this;
    }

    public
    function removeInstanceTextType(InstanceTextType $instanceTextType): self
    {
        if ($this->instanceTextTypes->removeElement($instanceTextType)) {
            // set the owning side to null (unless already changed)
            if ($instanceTextType->getCore() === $this) {
                $instanceTextType->setCore(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|FieldMap[]
     */
    public
    function getFieldMaps(): iterable
    {
        if (!isset($this->fieldMaps)) {
            $this->fieldMaps = new ArrayCollection();
        }

        return $this->fieldMaps;
    }

    public function getUnindexedFieldmaps(): iterable
    {
        $fieldMaps = [];
        foreach ($this->getFieldMaps() as $fieldMap) {
            $fieldMaps[] = $fieldMap;
        }
        return $fieldMaps;
    }

    public
    function getFieldMapByCodeOrColumn(string $code): ?FieldMap
    {
        // add aliases?
        return $this->getFieldMaps()->filter(fn(FieldMap $fieldMap) => in_array($code, [$fieldMap->getCode(), $fieldMap->getColumnName()]))
            ->first() ?: null;
    }


    public
    function addFieldMap(FieldMap $fieldMap): self
    {
        // hackish
        if (!isset($this->fieldMaps)) {
            $this->fieldMaps = new ArrayCollection();
        }
        $this->fieldMaps[$fieldMap->getCode()] = $fieldMap;
        return $this;
    }

    public
    function removeFieldMap(FieldMap $fieldMap): self
    {
        if ($this->fieldMaps->removeElement($fieldMap)) {
            // set the owning side to null (unless already changed)
//            if ($fieldMap->getProjectCoreRelationship() === $this) {
//                $fieldMap->setProjectCoreRelationship(null);
//            }
        }

        return $this;
    }

    /**
     * @return Collection|Reference[]
     */
    public
    function getReferences(): Collection
    {
        return $this->references;
    }

    public
    function addAllReference(Reference $allReference): self
    {
        if (!$this->references->contains($allReference)) {
            $this->references[] = $allReference;
            $allReference->setCore($this);
        }

        return $this;
    }

    public
    function removeAllReference(Reference $allReference): self
    {
        if ($this->references->removeElement($allReference)) {
            // set the owning side to null (unless already changed)
            if ($allReference->getCore() === $this) {
                $allReference->setCore(null);
            }
        }

        return $this;
    }

    public
    function getIdno(): ?string
    {
        return $this->getCoreCode();
    }

    /**
     * This can be used before an instance is created, for lookup
     *
     */
    public function createInstanceId(string|int $instanceCode): string
    {
        return sprintf("%s-%s", $this->getId(), $instanceCode);
    }

    // mostly used by barcode and import.  ripe for refactoring
    public function createInstance(string $code): Instance
    {
        $instance = new Instance($this, $code, $this->createInstanceId($code));
        $this->addInstance($instance);
        return $instance;
    }

    /**
     * @return Collection|Category[]
     */
    public
    function getCategories(): Collection
    {
        return $this->categories;
    }

    public
    function getConfigTableByType(string $configType): Collection
    {
        assert(false);
        return $this->categories->filter(fn(Category $moduleConfig) => $configType === $moduleConfig->getCategoryTypeCode());
    }

// e.g. ('type','currency'), ('list','base_material')
    public
    function getModuleConfigByCategoryAndCode(string $configCategory, string $code): ?Category
    {
        return $this->getConfigTableByType($configCategory)->filter(fn(Category $moduleConfig) => $code == $moduleConfig->getCode())->first() ?: null;
    }

    /**
     * @param string $configType
     * @param string $code
     * @return Category|null
     */
    public
    function getConfigItem(string $configType, string $code): ?Category
    {
        assert(false);
        return $this->getConfigTableByType($configType)
            ->filter(fn(Category $moduleConfig) => $code === $moduleConfig->getCode())->first() ?: null;
    }

    public
    function addCategory(Category $category, ?Category $parent = null): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories[] = $category;
            // all moduleConfigs need a parent, except for roots, which are stored in the config summary
            if (!$category->getParentId()) {
//                assert(!array_key_exists($category->getCategoryTypeCode(), $this->configSummary), $category->getCategoryTypeCode() . ' ' .  json_encode($this->configSummary));
//                $this->configSummary[$category->getCategoryTypeCode()] = [
//                    'count' => 0,
//                    'root' => $category, // will this persist?
//                    'rootId' => $category->getId()
//                ];
            } else {
//                assert(count($this->configSummary));
//                $this->configSummary[$category->getCategoryTypeCode()]['count']++;
            }
            $category->setCore($this);
            $this->getProject()->addCategory($category);
        }

        return $this;
    }

    public
    function removeCategory(Category $moduleConfig): self
    {
        if ($this->categories->removeElement($moduleConfig)) {
            $this->configSummary[$moduleConfig->getCategoryTypeCode()]--;
            // set the owning side to null (unless already changed)
            if ($moduleConfig->getCore() === $this) {
                $moduleConfig->setCore(null);
            }
        }

        return $this;
    }


    public
    function __toString(): string
    {
        return $this->getCoreCode();
    }

    public
    function isVisible(): bool
    {
        return $this->getIsEnabled();
    }

    public
    function getConfigSummary(): array
    {
        return $this->configSummary;
    }

    public
    function setConfigSummary(array $configSummary): self
    {
        $this->configSummary = $configSummary;

        return $this;
    }

    public
    function getTypeCount(): ?int
    {
        return -1;
        // is this really right?
        return $this->getCategoryFieldByCode(CategoryField::TYPE)?->getCategoryCount();
    }

    public
    function getSourceCount(): int
    {
        return $this->configSummary['source'];
    }

    /**
     * @return Collection<int, Instance>
     */
    public
    function getInstances(): Collection
    {
        return $this->instances;
    }

    public function addInstance(Instance $instance): self
    {
        if (!$this->instances->contains($instance)) {
            $this->instances->add($instance);
            $instance->setCore($this);
            $this->incrementInstanceCount();
        }

        return $this;
    }

    public
    function removeInstance(Instance $instance): self
    {
        if ($this->instances->removeElement($instance)) {
            // set the owning side to null (unless already changed)
            if ($instance->getCore() === $this) {
                $instance->setCore(null);
            }
        }

        return $this;
    }

    public
    function getCategoryTypeCodes(): array
    {
        // @todo: filter by specific type, maybe add to cores.yaml
        return CategoryField::CATEGORY_TYPES;
//        return array_keys($this->getCategoryTypes()->toArray());
//        // @todo: use doctrine indexBy
//        $codes = [];
//        foreach ($this->getCategoryTypes() as $categoryType) {
//            $codes[] = $categoryType->getCode();
//        }
//        return $codes;
    }

// e.g. 'type', 'source'
    public
    function getCategoryType(string $code, bool $throwErrorIfMissing = true): ?CategoryField
    {
        return $this->getCategoryType($code);
    }

    /**
     * @return Collection<int, Relation>
     */
    public
    function getRelations(): Collection
    {
        return $this->relations;
    }

    public
    function addRelation(Relation $relation): self
    {
        if (!$this->relations->contains($relation)) {
            $this->relations->add($relation);
            $relation->setCore($this);
            $this->getProject()->addRelation($relation);
        }

        return $this;
    }

    public
    function removeRelation(Relation $relation): self
    {
        if ($this->relations->removeElement($relation)) {
            // set the owning side to null (unless already changed)
            if ($relation->getCore() === $this) {
                $relation->setCore(null);
            }
        }

        return $this;
    }

    public
    function isObj(): bool
    {
        return $this->getCoreCode() === OldCore::OBJECT;
    }

    public
    function isLoc(): bool
    {
        return $this->getCoreCode() === OldCore::STORAGE;
    }

    public
    function isPer(): bool
    {
        return $this->getCoreCode() === OldCore::PERSON;
    }

    /**
     * @return Collection<string, Sheet>
     */
    public
    function getSheets(): Collection
    {
        return $this->sheets ?: new ArrayCollection([]);
    }

    public
    function addSheet(Sheet $sheet): self
    {
        assert(isset($this->sheets), "sheets is not set for " . $this->getCoreCode() . ' ' . $this->getProjectCode());
        $this->sheets->add($sheet);
//        $this->sheets[$sheet->getCode()] = $sheet;
        $sheet->setCore($this);
        $sheet->setSheetIdx($this->getSheets()->count() + 1);

        return $this;
    }

    public
    function removeSheet(Sheet $sheet): self
    {
        if ($this->sheets->removeElement($sheet)) {
            // set the owning side to null (unless already changed)
            if ($sheet->getCore() === $this) {
                $sheet->setCore(null);
            }
        }

        return $this;
    }

    public
    function getReferenceCount(): ?int
    {
        return $this->referenceCount;
    }

    public
    function setReferenceCount(int $referenceCount): self
    {
        $this->referenceCount = $referenceCount;

        return $this;
    }

    public
    function getTypeSpecificFields(string $fieldClass): array
    {
        return $fieldClass::getTypeSpecificFields();
    }

    public
    function getFieldClasses(): array
    {
        $x = [];;
        // for grouping fiels, likely needs more work
        foreach (Field::FIELD_CLASSES as $FIELD_CLASS) {
            $reflectionClass = (new ReflectionClass($FIELD_CLASS));
            dd($reflectionClass);
            $x[$reflectionClass->getShortName()] = $FIELD_CLASS;
        }
        return $x;
    }

    public
    function getOrderIdx(): ?int
    {
        return $this->orderIdx;
    }

    public
    function setOrderIdx(?int $orderIdx): self
    {
        $this->orderIdx = $orderIdx;

        return $this;
    }

    public
    function isLabelTranslatable(): bool
    {
        return !in_array($this->getCoreCode(), [OldCore::PERSON, OldCore::ORG]);
    }

    public function getIdFieldCode(): ?string
    {
        return $this->idFieldCode;
    }

    public function setIdFieldCode(?string $idFieldCode): self
    {
        $this->idFieldCode = $idFieldCode;

        return $this;
    }

    public function getSchema(): array
    {
        return $this->schema??[];
    }

    public function setSchema(?array $schema): self
    {
//        dump($this->getCode(), $schema);
        $this->schema = $schema;

        return $this;
    }

    /** @return array<string> */
    public function getTranslatableFieldCodes(): array
    {
        $translatableFields = [];
        foreach ($this->getFields() as $field) {
            if ($field->isTranslatable()) {
                $translatableFields[] = $field->getCode();
            }
        }
        return $translatableFields;

    }

    public function getMeiliCount(): ?int
    {
        return $this->meiliCount;
    }

    public function setMeiliCount(?int $meiliCount): static
    {
        $this->meiliCount = $meiliCount;

        return $this;
    }

    /**
     * @return Collection<int, Row>
     */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(Row $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setCore($this);
            $this->rowCount++;
        }

        return $this;
    }

    public function removeRow(Row $row): static
    {
        if ($this->rows->removeElement($row)) {
            $this->rowCount;
            // set the owning side to null (unless already changed)
            if ($row->getCore() === $this) {
                $row->setCore(null);
            }
        }

        return $this;
    }

    public function getOwner(): ?Owner
    {
        return $this->owner;
    }

    public function setOwner(?Owner $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function setRowCount(int $rowCount): static
    {
        $this->rowCount = $rowCount;

        return $this;
    }
}
