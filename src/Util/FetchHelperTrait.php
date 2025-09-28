<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Util;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

trait FetchHelperTrait
{
    private function makeWriter(SymfonyStyle $io, string $out): NdjsonWriter
    {
        $file = $out === '-' ? null : new \SplFileObject($out, 'w');
        return new NdjsonWriter($io, $file);
    }

    /**
     * Simple GET+JSON with retry/backoff on transient errors.
     */
    private function getJson(HttpClientInterface $http, string $url, array $options = [], int $retries = 3, int $initialBackoffMs = 400): array
    {
        $attempt = 0;
        $backoff = $initialBackoffMs;

        while (true) {
            try {
                return $http->request('GET', $url, $options)->toArray(false);
            } catch (TransportExceptionInterface $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }
                usleep($backoff * 1000);
                $backoff *= 2;
                $attempt++;
            }
        }
    }

    /**
     * Utility to add standard audit fields.
     */
    private function wrapInstitution(array $payload, string $source): array
    {
        $payload['_source']     = $source;
        $payload['_fetchedAt']  = (new \DateTimeImmutable())->format(DATE_ATOM);
        return $payload;
    }
}
