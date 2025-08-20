<?php

namespace Survos\PixieBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Survos\PixieBundle\Entity\Str;
use Survos\PixieBundle\Entity\StrTranslation;

class StrTranslationRepository extends ServiceEntityRepository
{
    private EntityManagerInterface $_em;
    public function __construct(ManagerRegistry $registry)
    {
        $this->_em = $registry->getManager();
        parent::__construct($registry, StrTranslation::class);
    }

    /**
     * Iterate all Str rows in stable order. Keeps memory flat.
     * @return \Generator<Str>
     */
    public function iterateAll(int $batchSize = 1000): \Generator
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.code', 'ASC');

        $first = 0;
        while (true) {
            $query = $qb->setFirstResult($first)->setMaxResults($batchSize)->getQuery();
            $rows  = $query->toIterable(); // no buffering

            $count = 0;
            foreach ($rows as $str) {
                $count++;
                yield $str;
            }
            if ($count === 0) {
                break;
            }
            $first += $batchSize;
            $this->_em->clear(); // important for memory!
        }
    }

    public function totalCount(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByLocale(string $locale): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s)')
            ->andWhere('s.locale = :loc')
            ->setParameter('loc', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Count distinct source strings that have at least one translation
    //
    // (no join needed; use IDENTITY() to count the FK column)
    public function totalSourceCount(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT IDENTITY(s.str))')
            ->getQuery()
            ->getSingleScalarResult();
    }



    /** @param string[] $codes
     *  @return array<string,string> map[code] => value
     */
    public function fetchByCodesAndLocale(array $codes, string $locale): array
    {
        if (!$codes) return [];
        $qb = $this->createQueryBuilder('t')
            ->select('s.code AS code, t.value AS value')
            ->join('t.str', 's')
            ->where('t.locale = :loc')
            ->andWhere('s.code IN (:codes)')
            ->setParameter('loc', $locale)
            ->setParameter('codes', $codes);

        $out = [];
        foreach ($qb->getQuery()->toIterable([], 1000) as $row) {
            $out[$row['code']] = $row['value'];
        }
        return $out;
    }

    /** Single lookup with fallback chain */
    public function fetchOne(string $code, string $locale, string $fallback = 'en'): ?string
    {
        $val = $this->fetchByCodesAndLocale([$code], $locale)[$code] ?? null;
        if ($val !== null || $locale === $fallback) return $val;
        return $this->fetchByCodesAndLocale([$code], $fallback)[$code] ?? null;
    }
}
