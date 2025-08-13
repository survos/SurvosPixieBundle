<?php

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\RowImportState;

/**
 * @extends ServiceEntityRepository<RowImportState>
 */
class RowImportStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RowImportState::class);
    }

}
