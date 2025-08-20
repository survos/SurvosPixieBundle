<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\CoreDefinition;

final class CoreDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r) { parent::__construct($r, CoreDefinition::class); }
    public function findOneByOwnerAndCore(string $owner, string $core): ?CoreDefinition
    {
        return $this->findOneBy(['ownerCode' => $owner, 'core' => $core]);
    }
}
