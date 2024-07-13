<?php

namespace Survos\PixieBundle\Message;

final class PixieTransitionMessage
{

    public function __construct(
        public readonly string $pixieCode,
        public readonly string $key,
        public readonly string $table,
        public readonly string $transition,
        public readonly string $workflow,
    ) {
    }
}
