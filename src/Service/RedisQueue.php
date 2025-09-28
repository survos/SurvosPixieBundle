<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisQueue
{
    private \Redis|\RedisCluster $redis;

    public function __construct(?string $redisDsn = null)
    {
        // Uses env var REDIS_URL if provided, else local default
        $dsn = $redisDsn ?? ($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        /** @var \Redis|\RedisCluster $conn */
        $conn = RedisAdapter::createConnection($dsn, ['lazy' => false]);
        $this->redis = $conn;
    }

    private function key(string $queue): string
    {
        return sprintf('queue:%s', $queue);
    }

    /**
     * Publish an array of objects (they’ll be serialized).
     * Returns the new list length after push.
     * NOTE: This uses PHP serialize() because you asked for serialized objects.
     */
    public function publish(string $queue, array $objects): int
    {
        // Security note: serialize/unserialize carry risks. Use only with trusted data/classes.
        $payload = serialize($objects);
        // LPUSH so newest messages are near the head; consumer BRPOP reads from tail.
        return (int) $this->redis->lPush($this->key($queue), $payload);
    }

    /**
     * Blocking pop with timeout (seconds). Returns array of objects, or null on timeout.
     * @return array<object>|null
     */
    public function consumeOne(string $queue, int $timeoutSeconds = 5): ?array
    {
        $res = $this->redis->brPop([$this->key($queue)], $timeoutSeconds);
        if ($res === null || $res === false) {
            return null; // timeout
        }

        // $res is [key, value]
        $serialized = $res[1] ?? '';
        /** @var array<object>|false $decoded */
        $decoded = @unserialize($serialized, ['allowed_classes' => true]); // you asked for objects
        if ($decoded === false || !is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
}
