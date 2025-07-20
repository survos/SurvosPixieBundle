<?php

namespace Survos\PixieBundle\Traits;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use function Symfony\Component\String\u;

/**
 * @ ApiResource()
 * @ ApiFilter(BooleanFilter::class, properties={"is_enabled"})
 * @ ApiFilter(SearchFilter::class, properties={"list":"exact", "ca_list_item_id": "exact", "list_item_type": "exact"})
 */

trait LabelTrait
{
    public function __construct()
    {
        $this->label = null;
    }

/**
 * Used locale to override Translation listener`s locale
 * this is not a mapped field of entity metadata, just a simple property
 */
//#[Gedmo\Locale]
//    protected $locale;

//    public function setTranslatableLocale(string $locale): self
//    {
//        $this->locale = $locale;
//        return $this;
//    }
//

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['translation'])]
    private ?string $locale = null;

    #[Groups(['read', 'tree', 'preview', 'spreadsheet'])]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[ApiFilter(SearchFilter::class, strategy: 'ipartial')]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['read', 'write'])]
    #[ApiFilter(SearchFilter::class, strategy: 'ipartial')]
    private ?string $description = null;

    #[Groups(['tree', 'read', 'preview'])]
    public function getLabel(): string
    {
        return isset($this->label) ? (string) $this->label : '';
        //  ?: $this->getIdno() ?: $this->getId();
    }

    #[Groups(['tree', 'write'])]
    public function setLabel(?string $label): self
    {
        $this->label = u($label)->slice(0, 255)->trim();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description ?? null;
    }

    public function setDescription(?string $description): self
    {
        $this->description = empty($description) ? null : trim($description);
        assert($this->description);

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getLabel() ?: (string) $this->getId();
    }
}
