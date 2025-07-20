<?php

namespace Survos\PixieBundle\Entity;
// ./c make:entity Survos\\PixieBundle\\Entity\\Row

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\RowRepository;
use Survos\PixieBundle\Traits\CoreIdTrait;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: RowRepository::class)]
#[ORM\Index(name: 'row_core', fields: ['core'])]
#[Groups(['row.read'])]
class Row implements MarkingInterface, \Stringable
{
    use CoreIdTrait;
    use MarkingTrait;

    #[ORM\ManyToOne(inversedBy: 'rows')] # , cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    protected Core $core;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $data = null;

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
    private Collection $images;

    #[ORM\Column(nullable: true)]
    private ?array $raw = null;

    public function __construct(?Core $core=null, ?string $id=null)
    {
        $this->initCoreId($core, $id);
        $core->addRow($this); // if this is too slow, update rowCount here.
        $this->images = new ArrayCollection();
    }


    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function getRawValue(string $key, mixed $default=null, bool $throwErrorIfMissing = true): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getRawAll(string $key, mixed $default=null, bool $throwErrorIfMissing = true): array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function __toString()
    {
        return $this->getId();
    }

    // this is the meilisearch Key
    public function getKey()
    {
        return $this->getId();
    }

    /**
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
        return $this->getCore()->getOwner();

    }

    public function setRaw(?array $raw): static
    {
        $this->raw = $raw;

        return $this;
    }
    public function getRaw(): ?array
    {
        return $this->raw;
    }
}
