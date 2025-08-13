<?php

namespace Survos\PixieBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class PixieEntityManagerProvider
{
    /** @var array<string, EntityManagerInterface> */
    private array $pool = [];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PixieService $pixieService,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $pixieCode, ?string $subCode = null): EntityManagerInterface
    {
        $key = $subCode ? "$pixieCode:$subCode" : $pixieCode;
        if (isset($this->pool[$key])) {
            return $this->pool[$key];
        }

        /** @var EntityManagerInterface $baseEm */
        $baseEm = $this->registry->getManager('pixie'); // your existing EM alias
        $config = $baseEm->getConfiguration();
        $evm    = $baseEm->getEventManager();

        // Clone DBAL params and point to the desired sqlite file
        $params = $baseEm->getConnection()->getParams();
        unset($params['url']);                   // ensure 'path' wins for sqlite
        $params['driver'] = 'pdo_sqlite';
        $params['path']   = $this->pixieService->getPixieFilename($pixieCode, $subCode);

        $em = EntityManager::create($params, $config, $evm);

        // SQLite tuning
        $conn = $em->getConnection();
        $conn->executeStatement('PRAGMA foreign_keys = ON;');
        $conn->executeStatement('PRAGMA journal_mode = WAL;');
        $conn->executeStatement('PRAGMA synchronous = NORMAL;');

        return $this->pool[$key] = $em;
    }

    public function reset(string $pixieCode, ?string $subCode = null): void
    {
        $key = $subCode ? "$pixieCode:$subCode" : $pixieCode;
        if (!isset($this->pool[$key])) return;

        $em = $this->pool[$key];
        $em->clear();
        $em->getConnection()->close();
        unset($this->pool[$key]);
    }

    public function closeAll(): void
    {
        foreach ($this->pool as $em) {
            $em->clear();
            $em->getConnection()->close();
        }
        $this->pool = [];
    }
}
