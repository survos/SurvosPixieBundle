<?php

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Deprecated, moved to pixieService, this is problematic
 *
 * One EntityManager per pixie SQLite file.
 * Reuses mapping/listeners from the base 'pixie' manager; only the DBAL
 * connection params differ (pointing to the per-pixie file path).
 */
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
        $baseEm = $this->registry->getManager('pixie'); // must match your --em=pixie
        $config = $baseEm->getConfiguration();
        $evm    = $baseEm->getEventManager();

        // Clone DBAL params and point to the pixie file
        $params = $baseEm->getConnection()->getParams();
        unset($params['url'], $params['dbname']); // ensure 'path' wins for sqlite
        $params['driver'] = 'pdo_sqlite';
        $params['path']   = $this->pixieService->getPixieFilename($pixieCode, $subCode);


        $baseEm   = $this->registry->getManager('pixie');
        $baseConn = $baseEm->getConnection();
        $baseCfg  = $baseEm->getConfiguration();
        $baseEvm  = $baseEm->getEventManager();

        $params = $baseConn->getParams();
        unset($params['url'], $params['dbname']); // ensure sqlite 'path' wins
        $params['driver'] = 'pdo_sqlite';
        $params['path']   = $this->pixieService->getPixieFilename($pixieCode, $subCode);

        $conn = DriverManager::getConnection($params, $baseConn->getConfiguration(), $baseEvm);


// ...
        $connection = DriverManager::getConnection($params, $baseCfg);
        $em = new EntityManager($connection, $baseCfg); // ← correct on ORM 3.x


        // SQLite tunables
        $conn = $em->getConnection();
        try {
            $conn->executeStatement('PRAGMA foreign_keys = ON;');
            $conn->executeStatement('PRAGMA journal_mode = WAL;');
            $conn->executeStatement('PRAGMA synchronous = NORMAL;');
        } catch (\Throwable $e) {
            $this->logger->warning('SQLite PRAGMAs failed: ' . $e->getMessage());
        }

        return $this->pool[$key] = $em;
    }

    public function reset(string $pixieCode, ?string $subCode = null): void
    {
        $key = $subCode ? "$pixieCode:$subCode" : $pixieCode;
        if (!isset($this->pool[$key])) {
            return;
        }
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
