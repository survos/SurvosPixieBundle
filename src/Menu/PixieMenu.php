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

// events are
/*
// #[AsEventListener(event: KnpMenuEvent::NAVBAR_MENU2)]
#[AsEventListener(event: KnpMenuEvent::SIDEBAR_MENU, method: 'sidebarMenu')]
#[AsEventListener(event: KnpMenuEvent::PAGE_MENU, method: 'pageMenu')]
#[AsEventListener(event: KnpMenuEvent::FOOTER_MENU, method: 'footerMenu')]
#[AsEventListener(event: KnpMenuEvent::AUTH_MENU, method: 'appAuthMenu')]
*/

final class PixieMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] private string $env,
        private PixieService $pixieService,
        private ?MenuService $menuService=null,
        private ?Security $security=null,
        private ?AuthorizationCheckerInterface $authorizationChecker = null
    ) {
    }

    public function appAuthMenu(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->menuService->addAuthMenu($menu);
    }

    #[AsEventListener(event: KnpMenuEvent::PAGE_MENU)]
    public function pixiePageMenu(KnpMenuEvent $event): void
    {
        // there must be a pixie.  Messy, because this goes in app, need to add it to the config in pixie
        $menu = $event->getMenu();
        $this->add($menu, 'pixie_browse_configs');
        if ($pixieCode = $event->getOption('pixieCode')) {
            $this->addHeading($menu, $pixieCode);
            foreach (['pixie_overview','pixie_schema'] as $pixieRoute) {
                $this->add($menu, $pixieRoute, ['pixieCode' => $pixieCode]);
            }
            // get from config? Or ?
            $this->addHeading($menu, '|');
            $kv = $this->pixieService->getStorageBox($pixieCode);
            foreach ($kv->getTableNames() as $tableName) {
                $this->add($menu, 'pixie_browse', ['tableName' => $tableName, 'pixieCode' => $pixieCode], label: $tableName);
            }
        }

    }

    #[AsEventListener(event: KnpMenuEvent::NAVBAR_MENU)]
    public function navbarMenu(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();
        $options = $event->getOptions();

        //        $this->add($menu, 'app_homepage');
        // for nested menus, don't add a route, just a label, then use it for the argument to addMenuItem

        $nestedMenu = $this->addSubmenu($menu, 'PixieBundle');

        foreach (['bundles', 'javascript'] as $type) {
            // $this->addMenuItem($nestedMenu, ['route' => 'survos_base_credits', 'rp' => ['type' => $type], 'label' => ucfirst($type)]);
            $this->addMenuItem($nestedMenu, ['uri' => "#$type", 'label' => ucfirst($type)]);
        }
    }
}
