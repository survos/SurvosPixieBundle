<?php

namespace Survos\PixieBundle\Message;

final readonly class PixieTransitionMessage
{

    public function __construct(
        public string $pixieCode,
        public string $key,
        public string $table,
        public string $transition,
        public string $workflow,
        public ?string $transport=null,
    ) {
        assert($this->table);
    }
}
