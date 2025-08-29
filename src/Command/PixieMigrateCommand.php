<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Entity\Table;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\SqlViewService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:migrate', 'migrate the pixie databases')]
final class PixieMigrateCommand extends Command
{
    public function __construct(
        protected PixieService $pixieService,
        private LoggerInterface $logger,
        private SqlViewService $sqlViewService,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument("Limit to just this code")] ?string $pixieCodeFilter=null,
        #[Option] int $limit = 0,
        #[Option] ?bool $all = null,
    ): int {

        if (!$pixieCodeFilter && !$all) {
            $io->error("Either a pixie code or --all");
            return Command::FAILURE;
        }

        foreach ($this->pixieService->getConfigFiles() as $pixieCode => $rawConfig) {

            if (!$rawConfig || ($pixieCodeFilter && ($pixieCode <> $pixieCodeFilter))) {
                continue;
            }
            assert($pixieCode, "Missing code");

            if (!$rawConfig->isMuseum()) {
                $io->warning("Skipping $pixieCode because it is not a museum");
                continue;
            }
            if (!$rawConfig->code) {
                dd($rawConfig);
            }
            assert($rawConfig->code === $pixieCode, "mismatch $pixieCode and " . $rawConfig->code);

            $io->section(sprintf('Migrating %s / %s', $rawConfig->code, $rawConfig->getSource()?->label));

            // 1) Point the pixie EM at the correct SQLite DB (no queries yet)
            $em = $this->pixieService->switchToPixieDatabase($pixieCode);

            // 2) Make sure the schema exists (safe on first run; a no-op later)
            $this->pixieService->ensureSchema($em);

            // 3) Run template→target schema diffs and create/update views
            $this->pixieService->migrateDatabase($rawConfig, $em);

            // 4) Now it's safe to read/write data (owner/core/etc.)
            $ctx = $this->pixieService->getReference($pixieCode);

            // IMPORTANT: Start transaction AFTER all database switching is complete
            $em->beginTransaction();
            try {
                // Ensure Owner row exists
                $owner = $em->find(Owner::class, $pixieCode);
                if (!$owner) {
                    $owner = new Owner($pixieCode, $pixieCode);
                    $em->persist($owner);
                    $em->flush();
                }

                // Update owner fields
                $owner->name      = $ctx->config->getSource()?->label ?? $pixieCode;
                $owner->pixieCode = $pixieCode;
                $owner->locale    = $ctx->config->getSource()?->locale ?? 'en';

                // Maintain a managed Owner reference in context
                $ctx->ownerRef = $em->getReference(Owner::class, $pixieCode);

                // Ensure tables/cores exist for this config
                $tableRepo = $em->getRepository(\Survos\PixieBundle\Entity\Table::class);

                foreach ($rawConfig->getTables() as $t) {   // ✅ use YAML here?

                    // hack! Something's specific to mus v pixie (app)
                    $name = is_object($t) ? $t->getName(): $t['name'];

                    $tbl = $tableRepo->find($name);
                    if (!$tbl) {
                        $tbl = new Table($owner, $name, 'list');
                        $em->persist($tbl);
                    }

                    // Create/fix Core (ownerRef is set above)
                    $core = $this->pixieService->getCoreInContext($ctx, $name, autoCreate: true);

                    assert( $ctx->em->contains($core), "core not in em");
                }

                $em->flush();
                $em->commit();
                assert($owner->cores->count() === ($repoCount = $em->getRepository(Core::class)->count()),
                    sprintf("core count mismatch %d <> %d", $owner->cores->count(), $repoCount));

                $io->success(sprintf('%s created/updated with %d cores', (string) $owner, count($owner->cores ?? [])));

            } catch (\Throwable $e) {
                // Check if transaction is still active before attempting rollback
                if ($em->getConnection()->isTransactionActive()) {
                    $em->rollback();
                }
                $io->error(sprintf('Failed to migrate %s: %s', $pixieCode, $e->getMessage()));
                throw $e;
            }
        }

        return self::SUCCESS;
    }
}