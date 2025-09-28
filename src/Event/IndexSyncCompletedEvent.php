<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class IndexSyncCompletedEvent extends Event
{
    public function __construct(
        public readonly int $totalIndexes,
        public readonly int $pixieIndexes,
        public readonly array $stats,
        public readonly ?string $pixieCode = null,
    ) {}
}
