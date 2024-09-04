<?php

namespace Survos\PixieBundle\Menu;

use Survos\BootstrapBundle\Event\KnpMenuEvent;
use Survos\BootstrapBundle\Service\MenuService;
use Survos\BootstrapBundle\Traits\KnpMenuHelperInterface;
use Survos\BootstrapBundle\Traits\KnpMenuHelperTrait;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PixieItemMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] private string $env,
        private PixieService $pixieService,
        private ?MenuService $menuService=null
    ) {
    }

    #[AsEventListener(event: KnpMenuEvent::PAGE_MENU)]
    #[AsEventListener(event: KnpMenuEvent::SIDEBAR_MENU)]
    public function pixiePageMenu(KnpMenuEvent $event): void
    {
        // there must be a pixie.  Messy, because this goes in app, need to add it to the config in pixie
        $menu = $event->getMenu();
        if (!$itemKey = $event->getOption('itemKey')) {
            return; // pixie  browse should be handled outside of this menu.
        }
        if (!$tableName = $event->getOption('tableName')) {
            return;
        }
        if (!$pixieCode = $event->getOption('pixieCode')) {
            return;
        }
//        return;
        $kv = $this->pixieService->getStorageBox($pixieCode);
        $this->addHeading($menu, 'key is ' . $itemKey);

        if ($item = $kv->get($itemKey, $tableName)) {
            $this->addHeading($menu, $item->getKey());
            $this->add($menu, 'pixie_show_record', $item->getRp(), 'show');
            $this->add($menu, 'pixie_share_item', $item->getRp());
        }
    }

}
