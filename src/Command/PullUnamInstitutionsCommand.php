<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Util\FetchHelperTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('pixie:pull:unam-institutions', 'Fetch institutions from UNAM ADA API as NDJSON')]
final class PullUnamInstitutionsCommand
{
    use FetchHelperTrait;

    public function __construct(private readonly HttpClientInterface $http) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Output file path (or "-" for stdout)')]
        string $out = '-',

        #[Option('Base URL of ADA API', 'u')]
        string $base = 'https://datosabiertos.unam.mx/api/ada',

        #[Option('Relative path to list institutions', 'p')]
        string $path = '/instituciones',

        #[Option('Extra query string, e.g. "estado=activo"', 'q')]
        ?string $query = null,

        #[Option('Page size (if paginated)', 's')]
        int $pageSize = 100,

        #[Option('Max pages (0 = all)', 'm')]
        int $maxPages = 0,
    ): int {
        $writer = $this->makeWriter($io, $out);

        $page = 0;
        $fetched = 0;

        do {
            $qs = ['_page' => $page + 1, '_limit' => $pageSize];
            if ($query) {
                parse_str($query, $extra);
                $qs = array_merge($qs, $extra);
            }

            $url = rtrim($base, '/') . '/' . ltrim($path, '/');
            $data = $this->getJson($this->http, $url, ['query' => $qs, 'timeout' => 60]);

            // ADA nodes often return {"results":[...]} or a plain array.
            $items = $data['results'] ?? $data ?? [];
            $count = 0;

            foreach ($items as $row) {
                $inst = $this->wrapInstitution([
                    'id'       => $row['id'] ?? $row['code'] ?? $row['siglas'] ?? null,
                    'name'     => $row['nombre'] ?? $row['name'] ?? null,
                    'acronym'  => $row['siglas'] ?? $row['acronym'] ?? null,
                    'url'      => $row['url'] ?? $row['website'] ?? null,
                    'country'  => 'MX',
                    'raw'      => $row,
                ], 'UNAM-ADA');

                $writer->write($inst);
                $count++;
            }

            $fetched += $count;
            $io->writeln(sprintf('<info>Page %d: %d items</info>', $page + 1, $count));

            $page++;
            $more = $count > 0 && ($maxPages === 0 || $page < $maxPages);
        } while ($more);

        $io->success("UNAM institutions written: {$fetched} → {$out}");
        return 0;
    }
}
