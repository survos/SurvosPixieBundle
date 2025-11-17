<?php

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RowRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_row_core_idwithin', columns: ['core_id','id_within_core'])]
#[ORM\Index(name: 'row_core', columns: ['core_id'])]
//#[ORM\Index(name: 'row_core_label', columns: ['core_id','label'])]
//
//#[ORM\Index(name: 'row_core_code', columns: ['core_id','id'])]
#[ORM\Index(name: 'row_core_marking', columns: ['core_id','marking'])] // optional but handy for workflows
#[Groups(['row.read'])]
class Row implements
//    TranslatableByCodeInterface, // this should be handled in babel-bundle now!
    MarkingInterface, \Stringable
{
    use MarkingTrait;
//    use TranslatableFieldsByCode;


// NOT mapped: transient resolved strings for this request/run, resolved in PixiePostLoadListener::class
    private array $_resolved = [];

    // Codes for all translatable fields (uniform!)
    #[ApiProperty(description: 'Codes for all translatable fields (uniform!)')]
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $t_codes = null;

    // Virtuals for common fields (others are accessed via $row->translated('field'))
    #[ApiProperty(description: 'label used when this row is presented in a list (e.g. facet)')]
    public string $label       { get => $this->translated('label'); }

    #[ApiProperty(description: 'The raw label from which the translation code comes from.  Might not be unique.  Not translated.')]
    #[ORM\Column(type: Types::STRING)]
    public string $rawLabel;

    #[ApiProperty(description: 'shortcut to description')]
    public string $description { get => $this->translated('description'); }

    // ——— API used by importer / normalizers ———
    public function bindTranslatableCode(string $field, string $code): void
    {
        $this->t_codes ??= [];
        $this->t_codes[$field] = $code;
    }

    /** field => code (used by the listener) */
    public function getStrCodeMap(): array
    {
        return $this->t_codes ?? [];
    }

    // ——— called by the postLoad listener ———
    public function __setResolvedString(string $field, ?string $value): void
    {
        $this->_resolved[$field] = $value ?? '';
    }

    protected function translated(string $field): string
    {
        return $this->_resolved[$field] ?? '';
    }

    #[ORM\ManyToOne(inversedBy: 'rows')] # , cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    public Core $core;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[ApiProperty(description: 'the normalized object that meilisearch indexes, all of the important data in key/value pairs EXCEPT translatable strings')]
    public ?array $data = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[ApiProperty(description: 'the raw data for debugging')]
    public ?array $rawData  = null;

    /**
     * keyed by locale, then by translatable field name, e.g. en.label, en.description
     *
     * @var array|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $translations = null;

    /**
     * @var Collection<int, OriginalImage>
     */
    #[ORM\OneToMany(targetEntity: OriginalImage::class, mappedBy: 'row', orphanRemoval: true)]
    #[Groups(['row.images'])]
    public Collection $images;

    #[ORM\Column(nullable: true)]
    #[ApiProperty(description: 'The original json data, before any normalization or cleanup.  Debug only, should not be read in production')]
    public ?array $raw = null;

    #[ORM\Column(type: Types::STRING)]
//    #[Assert\NotNull()]
    #[Groups(['row.read'])]
    private(set) ?string $idWithinCore;

    #[ORM\Column(type: Types::STRING)]
    #[ORM\Id()]
    #[Groups(['row.read'])]
    #[ApiProperty(description: 'single key, composite of core.code - row.idWithinCore')]
    private(set) ?string $id;

    public static function RowIdentifier(Core $core, string $id): string
    {
        return $core->code . '-' . $id;
    }


    public function __construct(
        ?Core $core=null,
        ?string $id=null)
    {
        $this->core = $core;
        $this->idWithinCore = $id;
        $this->id = self::RowIdentifier($core, $id);
//        $this->initId(id: Row::RowIdentifier($core, $idWithinCore));
//        $core->addRow($this); // if this is too slow, update rowCount here.
        $this->core = $core;
        $core->rowCount++;

        $this->images = new ArrayCollection();
    }

    public function getRawValue(string $key, mixed $default=null, bool $throwErrorIfMissing = true): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function __toString() { return $this->id; }

    /**
     *
     * Someday we may move images to be handled in postload, but for now we'll keep them as a relation.
     *
     * @return Collection<int, OriginalImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(OriginalImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setRow($this);
        }

        return $this;
    }

    public function removeImage(OriginalImage $image): static
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getRow() === $this) {
                $image->setRow(null);
            }
        }

        return $this;
    }

    public function getOwner(): Owner
    {
        return $this->core->owner;

    }

    public function setLabel(string $label): static
    {
        $this->rawLabel = $label;
        return $this;
    }

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }
}
