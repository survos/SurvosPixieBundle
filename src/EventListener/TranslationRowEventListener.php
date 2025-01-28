<?php

namespace Survos\PixieBundle\EventListener;

use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Service\PixieTranslationService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Event\SubmitEvent;

final readonly class TranslationRowEventListener
{

    public function __construct(
        // ugh, this is from app!!
        private EventDispatcherInterface $eventDispatcher,
        private ?PixieTranslationService $translationService = null,
    )
    {
    }

    #[AsEventListener(event: RowEvent::class)]
    public function onRowEvent(RowEvent $event): void
    {
        return;
        $item = $event->item;
        foreach ($event->config->getTable($event->tableName)->getTranslatable() as $field) {
//            $this->eventDispatcher->dispatch(new Translat)

        }
        dd($event);
    }
}
