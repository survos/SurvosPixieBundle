<?php

namespace Survos\PixieBundle\EventListener;

use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

final class PixieControllerEventListener
{
    public function __construct(
        private RequestStack $requestStack,
//        #[Target('pixieEntityManager')] private EntityManagerInterface $pixieEntityManager,
        private PixieService $pixieService,
    )
    {

    }
    #[AsEventListener(ControllerEvent::class)]
    public function onKernelController(ControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $pixieCode =  $request->get('pixieCode');
        if ($pixieCode) {
            $this->pixieService->selectConfig($pixieCode);
            // now we can also check permissions!
//            was $this->sqliteService->setPixieEntityManager($pixieCode);
        }
    }
}
