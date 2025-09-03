<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Service\MeiliSettingsBuilder;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Util\IndexNameResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:meili:settings', 'Build (and optionally apply) Meili settings from DTO mappers')]
final class PixieMeiliSettingsCommand
{
    public function __construct(
        private readonly MeiliSettingsBuilder $builder,
        private readonly MeiliService         $meili,
        private readonly MeiliIndexer         $meiliIndexer, // a pixie service
        private PixieService                  $pixieService, private readonly MeiliService $meiliService,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pixie code')] string $pixieCode,
        #[Option(name: 'core', description: 'Core code',)] string $core = 'obj',
        #[Option(name: 'locale', description: 'Locale (used for index name)')] ?string $locale = null,
        #[Option(name: 'apply', description: 'Apply to Meili index',)] bool $apply = false
    ): int {
        $ctx = $this->pixieService->getReference($pixieCode);
        $locale ??= $ctx->ownerRef->locale;
        $settings = $this->builder->build($pixieCode, $core);
        // no core!
        $index = $this->meiliIndexer->resolveIndexName($pixieCode, $locale);

        $io->title("Meili settings for $index");
        $io->writeln(json_encode($settings, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

        if ($apply) {
//            $this->meili->ensureIndex($index, 'id');
            $this->meiliService->getIndex($index)->updateSettings($settings);
            $io->success("Applied settings to $index");
        } else {
            $io->note("Run with --apply to push these settings to Meili.");
        }
        return 0;
    }
}
