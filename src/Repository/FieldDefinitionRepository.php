<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\FieldDefinition;

final class FieldDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $r) { parent::__construct($r, FieldDefinition::class); }

    /** @return FieldDefinition[] */
    public function findByOwnerAndCore(string $owner, string $core): array
    {
        return $this->findBy(['ownerCode' => $owner, 'core' => $core], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /** @return FieldDefinition[] */
    public function findTranslatable(string $owner, string $core): array
    {
        return $this->findBy(['ownerCode' => $owner, 'core' => $core, 'translatable' => true], ['position' => 'ASC']);
    }
}
