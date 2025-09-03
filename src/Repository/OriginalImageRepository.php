<?php

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\OriginalImage;
use Survos\SaisBundle\Service\SaisClientService;

/**
 * @extends ServiceEntityRepository<OriginalImage>
 */
class OriginalImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OriginalImage::class);
    }

    public function findByUrl(string $url, string $root): ?OriginalImage
    {
        $calc = SaisClientService::calculateCode($url, $root);
        return $this->find($calc);
    }

//    /**
//     * @return OriginalImage[] Returns an array of OriginalImage objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('o.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?OriginalImage
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
