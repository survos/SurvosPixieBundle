<?php

// UlidTrait

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

trait IdTrait
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, unique: true)]
    #[ApiProperty(identifier: true)]
    #[Assert\NotNull()]
    #[Groups(['instance.id'])]
    # unique system-wide, code is unique within owner.  Prefixed by core
    protected ?string $id;

    /**
     * Unique identifier within a project or projectCore or categoryType, etc.  for url, import/export
     */
//    #[Groups(['spreadsheet', 'tree', 'read', 'write', 'preview', 'instance.read'])]
    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    #[Assert\Length(max: 255, maxMessage: 'The code cannot be longer than {{ limit }} characters')]
    protected string $code;

    public function getCode(): string
    {
        //        if (!isset($this->code)) { return self::class . ' no-code'; }
        assert(isset($this->code), $this::class . " code not set!");
        return $this->code;
    }

    public function setCode(string $code): self
    {
        assert($code <> 'obj.intrinsic.images', $this::class);
        //        $code = substr($code, 0, 255);
        assert(strlen($code) <= 255, "$code is too long");
//        assert(!preg_match('/\s/', $code), "code $code contains spaces");
        $code = str_replace('/\s/', '', $code); // should really fix at the call level
        $this->code = $code;
//        if (empty($this->name)) {
//            $this->name = $code;
//        }
        return $this;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function initId(?string $id=null, ?string $code = null): self
    {
        $this->id = $id?: $code;
        if ($code || $id) {
            $this->code = $code?:$id;
        }
        return $this;
    }

    #[Groups(['tree', 'id'])]
    public function getId(): ?string
    {
//        assert(isset($this->id), "Missing id for " . $this::class);
        return $this->id;
        //        return isset($this->id) ? $this->id : null;
    }
//

}
