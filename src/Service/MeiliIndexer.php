<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Meilisearch\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Minimal Meili indexer for Pixie: create/index/get settings.
 * Constructor args are DI-injected (no direct env reads here).
 *
 * By default we read from env via DI attributes:
 *  - MEILI_URL
 *  - MEILI_MASTER_KEY (nullable)
 *
 * You can also bind these in services.yaml instead of env vars.
 */
final class MeiliIndexer
{
    private Client $client;

    public function __construct(
        #[Autowire(env: 'MEILI_SERVER')]
        string $url,
        #[Autowire(env: 'MEILI_MASTER_KEY')]
        ?string $key = null,
    ) {
        $this->client = new Client($url, $key);
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function ensureIndex(string $indexName, string $primaryKey = 'id'): void
    {
        $this->client->createIndex($indexName, ['primaryKey' => $primaryKey]);
    }

    /** @return array<string,mixed>|null */
    public function getSettings(string $indexName): ?array
    {
        try {
            return $this->client->index($indexName)->getSettings();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $docs
     */
    public function indexDocs(string $indexName, array $docs, string $primaryKey = 'id'): void
    {
        if (!$docs) {
            return;
        }
        $this->client->index($indexName)->updateDocuments($docs, $primaryKey);
    }
}
