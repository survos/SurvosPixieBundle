<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Util\FetchHelperTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('pixie:pull:unam-obras', 'Fetch "Obra artística" records from UNAM ADA API as NDJSON')]
final class PullUnamObrasCommand
{
    use FetchHelperTrait;

    public function __construct(
        private LoggerInterface $logger,
        private readonly HttpClientInterface $http) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Output file path (or "-" for stdout)')]
        string $out = '-',

        #[Option('Base URL of ADA API', 'u')]
        string $base = 'https://datosabiertos.unam.mx/api/ada',

        // Pattern is a dataset/code selector. AFMT = Archivo Fotográfico Manuel Toussaint (artworks).
        // Wildcards are supported by ADA (e.g., IIE:AFMT:*).
        #[Option('Dataset/code pattern (wildcards OK)', 'p')]
        string $pattern = 'IIE:AFMT:*',

        #[Option('Extra query string, e.g. "_limit=200&orderby=fecha"', 'q')]
        ?string $query = null,

        #[Option('Page size (if endpoint supports _limit)', 's')]
        int $pageSize = 200,

        #[Option('Max pages (0 = all)', 'm')]
        int $maxPages = 0,
    ): int {
        $writer = $this->makeWriter($io, $out);

        $page = 0;
        $fetched = 0;

        do {
            // Many ADA endpoints accept _page/_limit; harmless if ignored.
            $qs = ['_page' => $page + 1, '_limit' => $pageSize];
            if ($query) {
                parse_str($query, $extra);
                $qs = array_merge($qs, $extra);
            }

            // Pattern fetch: /data/{pattern}  e.g., /data/IIE:AFMT:*  (obras de arte)
            $url = rtrim($base, '/') . '/data/' . rawurlencode($pattern);

            $this->logger->warning($url);
            $data = $this->getJson($this->http, $url, ['query' => $qs, 'timeout' => 60]);

            // ADA returns either {results: [...], total: N} or a plain array.
            $items = $data['results'] ?? $data ?? [];
            $count = 0;

            foreach ($items as $row) {
                // Heuristic normalization for obra artística; keep everything in extra too.
                $norm = [
                    'source'     => 'UNAM-ADA',
                    'type'       => 'obra',
                    'id'         => $row['id'] ?? $row['identificador'] ?? null,
                    'title'      => $row['title'] ?? $row['titulo'] ?? null,
                    'creators'   => $row['creator'] ?? $row['autor'] ?? $row['autores'] ?? null,
                    'date'       => $row['date'] ?? $row['fecha'] ?? null,
                    'technique'  => $row['technique'] ?? $row['tecnica'] ?? null,
                    'measure'    => $row['measurements'] ?? $row['medidas'] ?? null,
                    'image'      => $row['image'] ?? $row['imagen'] ?? $row['thumbnail'] ?? null,
                    'license'    => $row['license'] ?? $row['licencia'] ?? $row['rights'] ?? $row['derechos'] ?? null,
                    'institution'=> $row['institution'] ?? $row['entidad'] ?? null,
                    '_fetchedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'extra'      => $row, // provider-specific payload
                ];
                dump($norm);

                $writer->write($norm);
                $count++;
            }

            $fetched += $count;
            $io->writeln(sprintf('<info>Page %d: %d obras</info>', $page + 1, $count));

            $page++;
            $more = $count > 0 && ($maxPages === 0 || $page < $maxPages);
        } while ($more);

        $io->success("UNAM obras written: {$fetched} → {$out}");
        return Command::SUCCESS;
    }
}
