<?php

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Instance;

/**
 * @method Core|null find($id, $lockMode = null, $lockVersion = null)
 * @method Core|null findOneBy(array $criteria, array $orderBy = null)
 * @method Core[]    findAll()
 * @method Core[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Core::class);
    }

    // @todo: api-platform
    /**
     * @return array<int, Instance>
     */
    public function getInstances(Core $core, int $limit = 255, $orderBy = []): iterable
    {
        $em = $this->getEntityManager();
        return $em->getRepository(Instance::class)->findBy([
            'core' => $core,
        ], $orderBy, $limit);
        $results = $em->getRepository($core->getEntityClass())->findBy([], $orderBy, $limit);
        return $results;
    }

    // /**
    //  * @return Core[] Returns an array of Core objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    public function findOneByCoreAndProjectCodes(string $coreId, string $projectId): ?Core
    {
        return $this->createQueryBuilder('pc')
            ->join('pc.core', 'core')
            ->join('pc.project', 'project')
            ->andWhere('project.code = :pid')
            ->andWhere('core.id = :cid')
            ->setParameters([
                'pid' => $projectId,
                'cid' => $coreId,
            ])
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
