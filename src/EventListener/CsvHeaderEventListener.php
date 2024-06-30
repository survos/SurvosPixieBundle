<?php

namespace Survos\PixieBundle\EventListener;

use Survos\PixieBundle\Event\CsvHeaderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Form\Event\SubmitEvent;

final class CsvHeaderEventListener
{
//    #[AsEventListener(event: CsvHeaderEvent::class)]
    public function onCsvHeaderEvent(CsvHeaderEvent $event): void
    {
//        dd($event);
//        $event->header = array_map('strtoupper', $event->header);
//        dd($event, $event->header);
        // ...
    }
}
