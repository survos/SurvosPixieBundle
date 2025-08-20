<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Schema\YamlSchemaSynchronizer;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:schema:sync', 'Compile survos_pixie YAML to Pixie DB schema (CoreDefinition/FieldDefinition)')]
final class PixieSchemaSyncCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly YamlSchemaSynchronizer $sync,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] string $pixieCode
    ): int
    {
        $ctx = $this->pixie->getReference($pixieCode);      // switch DB + ensure ORM
        $this->sync->sync($pixieCode, $ctx->config, 'v1');  // synchronizer uses $ctx internally
        $io->success("Schema compiled to DB for $pixieCode");
        return 0;
    }
}
