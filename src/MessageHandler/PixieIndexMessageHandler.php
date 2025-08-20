<?php
declare(strict_types=1);

namespace Survos\PixieBundle\MessageHandler;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Message\PixieIndexMessage;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\MeiliIndexer;
use Survos\PixieBundle\Util\IndexNameResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PixieIndexMessageHandler
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieDocumentProjector $projector,
        private readonly MeiliIndexer $indexer
    ) {}

    public function __invoke(PixieIndexMessage $m): void
    {
        $ctx   = $this->pixie->getReference($m->pixieCode);
        $owner = $ctx->ownerRef;
        $core  = $this->pixie->getCore($m->core, $owner);

        $rowId = Row::RowIdentifier($core, $m->idWithinCore);
        /** @var Row|null $row */
        $row   = $ctx->find(Row::class, $rowId);
        if (!$row) return;

        $doc   = $this->projector->project($ctx, $row, $m->locale);
        $index = IndexNameResolver::name($m->pixieCode, $m->core, $m->locale);

        $this->indexer->indexDocs($index, [$doc]);
    }
}
