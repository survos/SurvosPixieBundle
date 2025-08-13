<?php

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\RowImportStateRepository;

#[ORM\Entity(repositoryClass: RowImportStateRepository::class)]
#[ORM\Table(name: 'row_import_state')]
#[ORM\Index(name: 'idx_ris_updated_at', columns: ['updated_at'])]
class RowImportState
{
    public const HASH_LEN = 64;

    // Composite PK: (core_id, row_id)
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    public string $core_id;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    public string $row_id;

    /** -------------------- MAPPED BACKING FIELDS -------------------- */


    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** -------------------- PUBLIC HOOKED PROPERTIES (UNMAPPED) -------------------- */

    // Expose hook with Doctrine-ignored facade
    #[ORM\Column(length: 32,  nullable: true)]
    public ?string $contentHash {
        get => $this->contentHash;
        set {
            // optional: validate length/format here
            $this->contentHash = $value;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    // Read-only facade
    public \DateTimeImmutable $updated_at {
        get => $this->updatedAt;
    }

    /** -------------------- CONSTRUCTOR -------------------- */

    public function __construct(string $coreId, string $rowId, ?string $hash=null)
    {
        $this->core_id    = $coreId;
        $this->row_id     = $rowId;
        $this->contentHash = $hash;                 // set backing store directly
        $this->updatedAt   = new \DateTimeImmutable();
    }
}
