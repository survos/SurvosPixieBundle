<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stat_facet')]
#[ORM\UniqueConstraint(name: 'uniq_stat_facet', columns: ['owner_code','core','property','value'])]
class StatFacet
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $owner_code;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $core;

    #[ORM\Id]
    #[ORM\Column(length: 128)]
    public string $property;

    #[ORM\Id]
    #[ORM\Column(length: 512)]
    public string $value;         // facet value (normalized string)

    #[ORM\Column(type: 'integer')]
    public int $count = 0;

    public function __construct(string $owner, string $core, string $prop, string $value)
    {
        $this->owner_code = $owner;
        $this->core       = $core;
        $this->property   = $prop;
        $this->value      = $value;
    }
}
