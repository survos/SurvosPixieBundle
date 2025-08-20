<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stat_property')]
#[ORM\UniqueConstraint(name: 'uniq_stat_prop', columns: ['owner_code','core','property'])]
class StatProperty
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $owner_code;    // pixie code

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $core;          // core code (e.g. 'obj')

    #[ORM\Id]
    #[ORM\Column(length: 128)]
    public string $property;      // normalized property name (DTO field)

    #[ORM\Column(type: 'integer')]
    public int $non_empty = 0;

    #[ORM\Column(type: 'integer')]
    public int $total = 0;

    public function __construct(string $owner, string $core, string $prop)
    {
        $this->owner_code = $owner;
        $this->core       = $core;
        $this->property   = $prop;
    }
}
