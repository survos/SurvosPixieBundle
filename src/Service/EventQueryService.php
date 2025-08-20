<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\PixieContext;

final class EventQueryService
{
    /** @return Row[] */
    public function creatorsOf(PixieContext $ctx, Row $artwork): array
    {
        if (!class_exists('Survos\\PixieBundle\\Entity\\Event') || !class_exists('Survos\\PixieBundle\\Entity\\EventParticipant')) {
            return [];
        }

        $qb = $ctx->em->createQueryBuilder()
            ->select('actor')
            ->from('Survos\PixieBundle\Entity\EventParticipant', 'ep')
            ->join('ep.event', 'e')
            ->join('e.definition', 'd')
            ->join('ep.actor', 'actor')
            ->andWhere('e.subject = :row')
            ->andWhere('d.code = :type')
            ->andWhere('ep.roleCode = :role')
            ->setParameter('row', $artwork)
            ->setParameter('type', 'created')
            ->setParameter('role', 'creator');

        return $qb->getQuery()->getResult();
    }

    /** @return list<int> */
    public function createdYears(PixieContext $ctx, Row $artwork): array
    {
        if (!class_exists('Survos\\PixieBundle\\Entity\\Event')) {
            return [];
        }

        $qb = $ctx->em->createQueryBuilder()
            ->select('e.timeStart')
            ->from('Survos\PixieBundle\Entity\Event', 'e')
            ->join('e.definition', 'd')
            ->andWhere('e.subject = :row')
            ->andWhere('d.code = :type')
            ->setParameter('row', $artwork)
            ->setParameter('type', 'created');

        $years = [];
        foreach ($qb->getQuery()->getSingleColumnResult() as $start) {
            if (!$start) { continue; }
            $yy = (int)substr((string)$start, 0, 4);
            if ($yy) { $years[] = $yy; }
        }
        sort($years);
        return $years;
    }

    public function firstCreatedDate(PixieContext $ctx, Row $artwork): ?string
    {
        if (!class_exists('Survos\\PixieBundle\\Entity\\Event')) {
            return null;
        }

        $qb = $ctx->em->createQueryBuilder()
            ->select('e.timeStart')
            ->from('Survos\PixieBundle\Entity\Event', 'e')
            ->join('e.definition', 'd')
            ->andWhere('e.subject = :row')
            ->andWhere('d.code = :type')
            ->andWhere('e.timeStart IS NOT NULL')
            ->orderBy('e.timeStart', 'ASC')
            ->setMaxResults(1)
            ->setParameter('row', $artwork)
            ->setParameter('type', 'created');

        $res = $qb->getQuery()->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_SCALAR);
        return $res ? (string)$res['timeStart'] : null;
    }
}
