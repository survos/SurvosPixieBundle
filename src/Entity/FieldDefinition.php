<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\PixieBundle\Repository\FieldDefinitionRepository;

#[ORM\Entity(repositoryClass: FieldDefinitionRepository::class)]
#[ORM\Table(name: 'pixie_field_definition')]
#[ORM\UniqueConstraint(name: 'uniq_owner_core_header', columns: ['owner_code','core','incoming_header'])]
class FieldDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private(set) ?int $id;

    public function __construct(

        #[ORM\Column(length: 64)]
        public string $ownerCode,

        #[ORM\Column(length: 64)]
        public string $core,

        #[ORM\Column(length: 128)]
        public string $incomingHeader,

        #[ORM\Column(length: 128)]
        public string $code,

        #[ORM\Column(length: 32)]
        public string $kind,

        #[ORM\Column(length: 64, nullable: true)]
        public ?string $targetCore = null,

        #[ORM\Column(length: 8, nullable: true)]
        public ?string $delim = null,

        #[ORM\Column(type: 'boolean', nullable: true)]
        public ?bool $translatable = null,

        #[ORM\Column(type: 'integer', options: ['default' => 0])]
        public int $position = 0,
    ) {}
}
