<?php
namespace Survos\PixieBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Model\Config;

final class PixieContext
{
    public function __construct(
        public string $pixieCode,
        public Config $config,
        public EntityManagerInterface $em,
        public ?Owner $ownerRef=null, // managed reference (proxy) for this pixieCode
    ) {}

    public function repo(string $className): ServiceEntityRepository|EntityRepository
    {
        return $this->em->getRepository($className);
    }

    public function find(string $className, int|string $id): mixed
    {
        return $this->em->getRepository($className)->find($id);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
