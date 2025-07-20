<?php

namespace Survos\PixieBundle\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Row;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use function Symfony\Component\String\u;

trait CoreIdTrait
{
    use IdTrait;

    // each class will overwrite core with something like this:
//    #[ORM\ManyToOne(inversedBy: 'rows')]
//    #[ORM\JoinColumn(nullable: false, referencedColumnName: 'code')]
    protected Core $core;

    public function getCore(): ?Core
    {
        return $this->core;
    }

    public function setCore(?Core $core): static
    {
        $this->core = $core;

        return $this;
    }

    #[ORM\Column(type: Types::STRING)]
    #[Assert\NotNull()]
    #[Groups(['instance.id'])]
    protected ?string $idWithinCore;



    public function initCoreId(Core $core, string $idWithinCore): void
    {
        $this->core = $core;
        $this->idWithinCore = $idWithinCore;
        $this->initId(id: Row::RowIdentifier($core, $idWithinCore));

    }

    public function getIdWithinCore(): ?string
    {
        return $this->idWithinCore;

    }

    public static function RowIdentifier(Core $core, string $id): string
    {
        return $core->getCoreCode() . '-' . $id;
    }
    public function getUniqueIdentifiers(): array
    {
        return [
            'pixieCode' => $this->getOwner()->getCode(),
            'tableName' => $this->getCoreCode(),
            'key' => $this->getId(),

            'coreId' => (new \ReflectionClass($this))->getShortName(),
            'id' => $this->getId(),
        ];
    }

    #[Groups(['pc.read','pc.code'])]
    public function getCoreCode(): string
    {
        return $this->getCore()->getCoreCode();
    }



    public function getBarcode(): string
    {
        return sprintf('@%s.%s', 'coreCode', $this->getCode());
    }

    public function addFieldNames(array $fieldNames): self
    {
        $new = array_unique(array_merge($this->getFieldNames(), $fieldNames));
        $this->setFieldNames($new);

        $new = array_unique(array_merge($this->getOriginalFieldNames(), $fieldNames));
        $this->setOriginalFieldNames($new);

        return $this;
    }

    #[Groups(['rp', 'transitions'])]
    public function getrp(?array $addlParams = []): array
    {
        return array_merge($this->getUniqueIdentifiers(), $addlParams);
    }

    public static function getClassnamePrefix(?string $class = null): string
    {
        if (! $class) {
            $class = get_called_class();
        }
        $shortName = strtolower(u($x = (new \ReflectionClass($class))->getShortName())->snake()->lower()->ascii());

        //        $shortName = strtolower( (new \ReflectionClass(get_called_class()))->getShortName() );
        return $shortName;
    }

    public function getCoreCodeFromSheetName(): string
    {
        return $this->getCore()->getCode();
    }
}
