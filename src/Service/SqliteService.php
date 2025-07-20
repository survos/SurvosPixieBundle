<?php

/**
 * This is the basis of a migration tool, as it compares the existing database to some schema.
 *
 *
 */
declare(strict_types=1);


namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use \PDO;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\StorageBox;
use Survos\PixieBundle\Model\Property;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SqliteService
{
    public function __construct(
        private EntityManagerInterface                                   $pixieEntityManager,
        private readonly ManagerRegistry                                 $managerRegistry,
        #[Autowire('%kernel.project_dir%')] private string               $projectDir,
        #[Autowire('%env(DATABASE_PIXIE_URL)%')] private readonly string $pixieTemplateFilename
    )
    {
    }


    /**
     * Really SETs the pixie EM.
     *
     * @param string $code
     * @return EntityManagerInterface
     */
    public function setPixieEntityManager(string $code): EntityManagerInterface
    {
        assert(false, " use pixieService->getConfig that does the switch as well, selectConfig woulbe be better");
        $conn = $this->pixieEntityManager->getConnection();
        $conn->selectDatabase($this->dbName($code));
        return $this->pixieEntityManager;
    }


}
