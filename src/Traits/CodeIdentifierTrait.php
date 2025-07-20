<?php

namespace Survos\PixieBundle\Traits;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// maybe we don't need this here, since the ulid IS the id.
///**
// * @ApiResource()
// * @ApiFilter(BooleanFilter::class, properties={"is_enabled"})
// * @ApiFilter(SearchFilter::class, properties={"list":"exact", "ca_list_item_id": "exact", "list_item_type": "exact"})
// */

trait CodeIdentifierTrait
{
    #[ORM\Id]
    #[ORM\Column(type:Types::STRING, unique: true)]
    //    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ApiProperty(identifier: true)]
    #[Assert\NotNull()]
    #[Groups(['instance.id'])]
    protected string $id;

    public function initId(?string $code = null)
    {
        if ($code) {
            $this->id = $code;
            $this->code = $code;
        }
    }

    #[Groups(['tree', 'id'])]
    public function getId(): ?string
    {
        assert(isset($this->id), "Missing id for " . $this::class);
        return $this->id;
        //        return isset($this->id) ? $this->id : null;
    }

}
