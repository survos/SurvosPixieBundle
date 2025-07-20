<?php // really virtual tables, relations, categories, lists, attributes,

namespace Survos\PixieBundle\Entity;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\TableRepository;

#[ORM\Entity(repositoryClass: TableRepository::class)]
#[ORM\Table(name: 'tables')]
#[ApiResource]
class Table
{


    /**
     * @param string|null $name
     */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'tables')]
        #[ORM\JoinColumn(nullable: false)]
        private ?Owner $owner = null,


        #[ORM\Column(length: 255)]
        #[ORM\Id]
        private ?string $name = null,

        #[ORM\Column(length: 16)]
        private ?string $type = null,

    )
    {
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

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
}
