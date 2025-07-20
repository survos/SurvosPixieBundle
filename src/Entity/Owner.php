<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\Entity\App\Entity\Pixie\OwnerMember;
use App\Entity\IdInterface;
use App\Entity\UuidAttributeInterface;
use App\Service\SourceService;
use Survos\PixieBundle\Traits\IdTrait;
use App\Traits\UuidAttributeTrait;
use App\Workflow\OwnerWorkflowInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Deprecated;
use Survos\ApiGrid\Api\Filter\FacetsFieldSearchFilter;
use Survos\ApiGrid\State\MeiliSearchStateProvider;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\CoreBundle\Entity\RouteParametersTrait;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Entity\Field\Field;
use Survos\PixieBundle\Repository\OwnerRepository;
use Survos\WorkflowBundle\Attribute\Workflow;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Zenstruck\Alias;
use Zenstruck\Metadata;

#[ORM\Entity(repositoryClass: OwnerRepository::class)]
#[ORM\UniqueConstraint(name: 'owner_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'])]
#[UniqueEntity(fields: ['id'])]
//#[ApiResource(
//    shortName: 'owner',
//    operations: [new Get(), new Put(), new Delete(), new Patch(), new GetCollection(name: self::BROWSE_ROUTE)],
//    normalizationContext: [
//        'groups' => ['owner.read', 'instance.id', 'rp', 'translation', 'marking', 'locale', '_translations'],
//    ],
//    denormalizationContext: [
//        'groups' => ['write'],
//    ],
//)]
//#[GetCollection(
//    name: self::MEILI_ROUTE,
//    shortName: 'meili_owner',
//    uriTemplate: "meili/mus_Owner",
////    uriTemplate: "meili/{indexName}",
////    uriVariables: ["indexName"],
//    provider: MeiliSearchStateProvider::class,
//    normalizationContext: [
//        'groups' => ['project.read', 'tree', 'rp', 'marking'],
//    ]
//)]
# #[ApiFilter(MultiFieldSearchFilter::class, properties: ['name', 'projectCountBin', 'calcMeiliObjectCount', 'subdomain', 'projectsWithObjects', 'code','countryCode', 'projectCount', 'source','marking','locale'])]
//#[ORM\UniqueConstraint(name: 'project_name', columns: ['name'])]
#[UniqueEntity(fields: ['code'])]
//#[UniqueEntity(fields: ['name'])]
#[ApiFilter(FacetsFieldSearchFilter::class, properties: ['countryCode', 'source', 'marking', 'locale', 'hasImages', 'projectCountBin', 'projectsWithObjects'])]
#[ApiFilter(OrderFilter::class, properties: ['source', 'id', 'institutionId', 'countryCode', 'calcMeiliObjectCount', 'meiliObjectCount',
    'imageCount',
    'subdomain', 'source', 'projectCount', 'marking', 'locale'])]
#[Workflow(name: 'OwnerWorkflow', supports: [Item::class, Owner::class, 'stdClass'])]
#[Alias('owner')]
#[Metadata('meili', true)]
// instead of defining the info in survos_workflow
// comma-delimited list of tags for workflow:iterate
#[Metadata('workflow_tags', 'list,check,clean')]
class Owner implements \Stringable,  // Entity\Owner!
    RouteParametersInterface,
    UuidAttributeInterface,
    MarkingInterface,
    IdInterface,
//    TranslatableInterface,
//    TranslatableFieldsProxyInterface,
    OwnerWorkflowInterface
{
    use RouteParametersTrait;
    use UuidAttributeTrait;
    use IdTrait;
    use MarkingTrait;
//    use TranslatableTrait;
//    use TranslatableFieldsProxyTrait;

    /* this makes less sense here -- there's only one owner per pixie! */
    const BROWSE_ROUTE = 'api-browse-owners';
    const MEILI_ROUTE = 'api-search-owners';
    const MEILI_INDEX = 'Owner';

    public const array UNIQUE_PARAMETERS = [
        'ownerId' => 'code'
    ];

    #[Groups(['owner.read'])]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['project.read', 'owner.read'])]
    private ?string $countryCode = null;

//    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Project::class, cascade: ['persist'], orphanRemoval: true)]
//    #[ORM\JoinColumn(onDelete: 'CASCADE')]
//    #[ORM\OrderBy(['meiliObjectCount' => 'DESC'])]
    private Collection $projects;
    #[Groups(['owner.read'])]
    #[ORM\Column(nullable: true)]
    private ?int $institutionId = null;

//    #[ORM\Column(type: 'string', length: 64, nullable: false)]
////    #[Gedmo\Slug(fields: ['label'])]  // problems with translate behavior, since label is translated
//    #[Groups(['project.read', 'owner.read'])]
//    #[Assert\NotBlank()]
//    private string $code;

    #[Groups(['owner.read'])]
    #[ORM\Column(length: 255, nullable: true)]
    private string $source;

    #[Groups(['owner.read'])]
    #[ORM\Column(nullable: true)]
    private ?int $projectCount = 0;

    #[ORM\Column(length: 255, nullable: false)]
    #[Groups(['owner.read'])]
    private ?string $subdomain = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $css = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['owner.read'])]
    private ?string $cityState = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['owner.read'])]
    private ?int $meiliObjectCount = null;

//    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: OwnerMember::class, orphanRemoval: true, cascade: ['persist'])]
    #[Deprecated]
    private Collection $ownerMembers;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $serializedPixieConfig = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['owner.read'])]
    private ?array $coreCounts = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['owner.read'])]
    private ?string $pixieCode = null;

    /**
     * @var Collection<int, Field>
     */
    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'owner', orphanRemoval: true)]
    #[ORM\OrderBy(['orderIdx' => 'ASC'])]
    private Collection $fields;

    #[ORM\Column(nullable: true)]
    private ?array $stats = null;

    #[ORM\Column(nullable: true)]
    private ?array $translationCounts = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['owner.read'])]
    private ?array $tranStats = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['owner.read'])]
    private ?int $imageCount = null;

    /**
     * @var Collection<int, Core>
     */
    #[ORM\OneToMany(targetEntity: Core::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $cores;

    #[ORM\Column(length: 7)]
    private ?string $locale = null;

    /**
     * @var Collection<int, Table>
     */
    #[ORM\OneToMany(targetEntity: Table::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $tables;

    public function __construct(?string $code = null, ?string $source = null, ?array $hit = null)
    {
        $this->initId($code);
//        $this->initTranslationFields();
        if ($source) {
            $this->setSource($source);
        } else {
//            assert(false, "pass in source");
            $this->setSource($code);
        }
//        if ($source == PennService::SOURCE) {
//            $this->setLocale('en');
//        }
//        if ($hit) {
//            AppService::populateObjectFromData($this, $hit, throwErrorIfMissingProperty: false);
//        }
        $this->subdomain = $code;

        $this->marking = self::PLACE_NEW;
        $this->ownerMembers = new ArrayCollection();
        $this->fields = new ArrayCollection();
        $this->cores = new ArrayCollection();
        $this->tables = new ArrayCollection();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = mb_substr($name, 0, 64);

        return $this;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): self
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setOwner($this);
            $this->projectCount++;
        }

        return $this;
    }

    public function removeProject(Project $project): self
    {
        if ($this->projects->removeElement($project)) {
            // set the owning side to null (unless already changed)
            if ($project->getOwner() === $this) {
                $project->setOwner(null);
            }
            $this->projectCount--;
        }

        return $this;
    }

    public function getInstitutionId(): ?int
    {
        return $this->institutionId;
    }

    public function getIdInSource(): ?string
    {
        return (string)$this->getInstitutionId();
    }

    public function setInstitutionId(?int $institutionId): self
    {
        $this->institutionId = $institutionId;

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
     * @return Owner
     */
    public function setCode(string $code): Owner
    {
        $this->code = $code;
        return $this;
    }


    public function xxgetUniqueIdentifiers(): array
    {
//        $expressionLanguage = new ExpressionLanguage();
//        dump($expressionLanguage->evaluate('name', (array)$this));
//        dd($expressionLanguage->evaluate('owner.code', (array)$this));
        $x = [];
        foreach (self::UNIQUE_PARAMETERS as $parameter => $getter) {
            $x[$parameter] = $this->{'get' . $getter}();
        }
        return $x;
//        return ['ownerId' => $this->getCode()];
        // TODO: Implement getUniqueIdentifiers() method.
    }


    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        // eh.  config?  outdated.
//        AppService::assertInArray($source, SourceService::SOURCES);
        $this->source = $source;

        return $this;
    }

//    #[Groups(['owner.read'])]

    public function getProjectCount(): ?int
    {
        return $this->getProjects()->count();
        return $this->projectCount;
    }

    public function setProjectCount(?int $projectCount): static
    {
        $this->projectCount = $projectCount;

        return $this;
    }

    #[Groups(['owner.read'])]
    public function getSourceUrl(): ?string
    {
        return match ($this->source) {
            'centext' => 'https://centrotextilesmayas.org/',
            'musdig',
            'digmus',
            SourceService::MUSDIG => sprintf('https://global.museum-digital.org/institution/%s', $this->getInstitutionId()),
            SourceService::AHD => sprintf('https://catalogo-ahdsc.colmex.mx/otros-fondos/#%s', $this->getInstitutionId()),
            'penn' => 'https://www.penn.museum/collections/objects/data.php',
            'larco' => 'https://www.museolarco.org/catalogo/',
            'sic' => 'https://sic.gob.mx/ficha.php?table=museo&table_id=' . $this->getInstitutionId(),
            'archive',
            'custom',
            'cleveland',
            'nabolom',
            'spreadsheet',
            'excel' => null,
            'system' => null,
            'git' => null, // get this from git or links?
            'api' => null, // get this from links, which we don't really have yet, it's in the pixie
            SourceService::GITHUB,
            SourceService::WEB => null, //
            default => assert(false, "$this->source not handled in " . __METHOD__)
        };
    }


    #[Groups(['owner.read'])]
    public function getSourceImageUrl(?string $source = null, Item|Owner|null $item = null): ?string
    {
//        if (!$item) {
//            $item = $this;
//        }
        $imageUrl = match ($source ?? $this->source) {
            'musdig',
            'digmus',
            SourceService::MUSDIG => $this->get('image_url'),
//        sprintf('https://asset.museum-digital.org/%s/%s', $this->get('md_subset'), $this->get('image')),
            'penn' => 'https://www.penn.museum/img/brand/svg/logo_dark.svg',
            'archive',
            SourceService::NABOLOM,
            SourceService::AHD,
            SourceService::LARCO,
            SourceService::CLEVELAND,
            SourceService::WEB,
            'centext',
            'sic',
            'spreadsheet',
            SourceService::GITHUB,
            'excel',
            'git',
            'api',
            'custom' => null,
            'system' => null,
            default => assert(false, "$this->source not handled in " . __METHOD__)
        };
//    dd($imageUrl, $this->getAttributes());
        return $imageUrl;

    }

    #[Groups(['owner.read'])]
    public function getSourceIcon()
    {
        return match ($this->source) {
            SourceService::MUSDIG => 'https://global.museum-digital.org/favicon.ico',
            SourceService::PENN => 'https://www.penn.museum/img/brand/svg/logo_dark.svg',
            SourceService::CLEVELAND => 'https://www.clevelandart.org/sites/all/themes/cma_collection_v2/images/cma-logo-gray-f.svg',
            default => null // assert(false, "$this->source not handled in " . __METHOD__)
        };
    }

    #[Groups(['owner.read'])]
    public function getProjectCountBin(): int
    {
        // @todo: move this to a base function
        return $this->getProjectCount() ? floor(log10($this->getProjectCount()) + 1) : 0;
    }

    #[Groups(['owner.read'])]
    public function getProjectsWithObjects(): int
    {
        return $this->getProjects()->filter(fn(Project $project) => $project->getObjCore()?->getInstanceCount())->count();
    }

    public function __toString(): string
    {
        return $this->getCode();
    }

    public function getRelativeImagesDir()
    {
        return $this->getSource() . '/images/';
    }

    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): self
    {
        $this->subdomain = $subdomain;

        return $this;
    }

    public function getCss(): ?string
    {
        return $this->css;
    }

    public function setCss(?string $css): static
    {
        $this->css = $css;

        return $this;
    }

    public function getCityState(): ?string
    {
        return $this->cityState;
    }

    public function setCityState(?string $cityState): static
    {
        $this->cityState = $cityState;

        return $this;
    }

    public function getMeiliObjectCount(): ?int
    {
        return $this->meiliObjectCount;
    }

    public function setMeiliObjectCount(?int $meiliObjectCount): static
    {
        $this->meiliObjectCount = $meiliObjectCount;

        return $this;
    }

    #[Groups(['owner.read'])]
    public function getCalcMeiliObjectCount()
    {
        $count = 0;
        foreach ($this->getProjects() as $project) {
            $count += $project->getMeiliObjectCount();
        }
        return $count;
    }


    public function getProjectId(?string $projectCode): string
    {
        return sprintf("%s-%s", $this->getId(), $projectCode);
    }

    /**
     * @return Collection<int, OwnerMember>
     */
    public function getOwnerMembers(): Collection
    {
        return $this->ownerMembers;
    }

    public function addOwnerMember(OwnerMember $ownerMember): static
    {
        if (!$this->ownerMembers->contains($ownerMember)) {
            $this->ownerMembers->add($ownerMember);
            $ownerMember->setOwner($this);
        }

        return $this;
    }

    public function removeOwnerMember(OwnerMember $ownerMember): static
    {
        if ($this->ownerMembers->removeElement($ownerMember)) {
            // set the owning side to null (unless already changed)
            if ($ownerMember->getOwner() === $this) {
                $ownerMember->setOwner(null);
            }
        }

        return $this;
    }

    public function getFlowCode(): string
    {
        return 'OwnerWorkflow'; // eh, not true anymore
    }

    public function getSerializedPixieConfig(): ?string
    {
        return $this->serializedPixieConfig;
    }

    public function setSerializedPixieConfig(?string $serializedPixieConfig): static
    {
        $this->serializedPixieConfig = $serializedPixieConfig;

        return $this;
    }

    public function setPixieConfig(Config $config): static
    {
        $config = base64_encode(serialize($config));
        return $this->setSerializedPixieConfig($config);
    }

    public function getPixieConfig(): ?Config
    {
        $config = $this->getSerializedPixieConfig();
        return $config ? unserialize(base64_decode($config)) : null;
    }

    public function getCoreCounts(): ?array
    {
        return $this->coreCounts;
    }

    public function setCoreCounts(?array $coreCounts): static
    {
        $this->coreCounts = $coreCounts;

        return $this;
    }

    public function getPixieCode(): ?string
    {
        return $this->pixieCode;
    }

    public function setPixieCode(?string $pixieCode): static
    {
        $this->pixieCode = $pixieCode;

        return $this;
    }

    /**
     * @return Collection<int, Field>
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }


    public function addField(Field $field): static
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setOwner($this);
        }

        return $this;
    }

    /** @return Field[] */
    public function getFieldsByTable(): array
    {
        $results = [];
        foreach ($this->getFields() as $field) {
            foreach ($field->getTableNames() as $tableName => $stats) {
                $results[$tableName][] =
                    ['field' => $field, 'stats' => $stats];
            }
        }
        return $results;

    }

    public function removeField(Field $field): static
    {
        if ($this->fields->removeElement($field)) {
            // set the owning side to null (unless already changed)
            if ($field->getOwner() === $this) {
                $field->setOwner(null);
            }
        }

        return $this;
    }

    public function getStats(): ?array
    {
        return $this->stats;
    }

    public function setStats(?array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    public function getTranslationCounts(): ?array
    {
        return $this->translationCounts ?? [];
    }

    public function setTranslationCounts(?array $translationCounts): static
    {
        $this->translationCounts = $translationCounts;

        return $this;
    }

    public function getTranStats(): ?array
    {
        return $this->tranStats;
    }

    public function setTranStats(?array $tranStats): static
    {
        $this->tranStats = $tranStats;

        return $this;
    }

    public function getImageCount(): ?int
    {
        return $this->imageCount;
    }

    public function setImageCount(?int $imageCount): static
    {
        $this->imageCount = $imageCount;

        return $this;
    }

    /**
     * @return Collection<int, Core>
     */
    public function getCores(): Collection
    {
        return $this->cores;
    }

    public function addCore(Core $core): static
    {
        if (!$this->cores->contains($core)) {
            $this->cores->add($core);
            $core->setOwner($this);
        }

        return $this;
    }

    public function removeCore(Core $core): static
    {
        if ($this->cores->removeElement($core)) {
            // set the owning side to null (unless already changed)
            if ($core->getOwner() === $this) {
                $core->setOwner(null);
            }
        }

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * @return Collection<int, Table>
     */
    public function getTables(): Collection
    {
        return $this->tables;
    }

    public function addTable(Table $table): static
    {
        if (!$this->tables->contains($table)) {
            $this->tables->add($table);
            $table->setOwner($this);
        }

        return $this;
    }

    public function removeTable(Table $table): static
    {
        if ($this->tables->removeElement($table)) {
            // set the owning side to null (unless already changed)
            if ($table->getOwner() === $this) {
                $table->setOwner(null);
            }
        }

        return $this;
    }


}
