<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Util\IndexNameResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pixie:index', 'Project Rows and index to Meili (prints settings first)')]
final class PixieIndexCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieDocumentProjector $projector,
        private readonly MeiliIndexer $meiliIndexer, // pixie
        private readonly MeiliService $meili, // general, from our meili-bundle.
        private readonly SettingsService $settingsService, // from our meili-bundle, for creating/updating settings
        private readonly MeiliIndexer $indexer,
        private readonly LocaleContext $locale,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] ?string $pixieCode=null,
        #[Option('core')] string $core = 'obj',
        #[Option('locale')] string $loc = 'es',
        #[Option('batch')] int $batch = 500,
        #[Option('limit')] ?int $limit = null,
        #[Option('offset')] int $offset = 0,
        #[Option('dump the raw and normalized record')] ?bool $dump = null,
        #[Option('purge the meili index first')] ?bool $reset = null,
    ): int {
        $pixieCode ??= getenv('PIXIE_CODE');
        if (!$pixieCode) {
            $io->error("Pass in pixieCode or set PIXIE_CODE env var");
            return Command::FAILURE;
        }

        $this->locale->set($loc);

        $ctx   = $this->pixie->getReference($pixieCode);
        $owner = $ctx->ownerRef;
        $coreE = $this->pixie->getCore($core, $owner);

        $index = $this->meiliIndexer->resolveIndexName($pixieCode, $loc);
        $limit ??= ($this->env==='dev') ? 10 : 0;

        if ($reset) {
            $this->meili->reset($index);
//            $settings = $this->settingsService->getSettingsFromAttributes();
        }

        $io->title("Meili index: $index");
        $settings = $this->meiliIndexer->getSettings($index);
        if ($settings === null) {
            $io->warning("Index missing. Creating '$index' (pk=id).");
            $this->meiliIndexer->ensureIndex($index, 'id');
        } else {
            $io->writeln(json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $rows = $ctx->repo(Row::class)->findBy(['core' => $coreE], ['id' => 'ASC'], $limit ?: null, $offset);

        $docs = [];
        $i = 0;
        foreach ($rows as $row) {
            $dump && dump($row);
            $docs[] = ($projectedRow = $this->projector->project($ctx, $row, $loc));
            $dump && dump($projectedRow);
            if ((++$i % $batch) === 0) {
                $this->indexer->indexDocs($index, $docs);
                $docs = [];
            }
        }
        if ($docs) {
            $this->indexer->indexDocs($index, $docs);
        }

        $io->success("Indexed $i rows into $index.");
        return 0;
    }
}
