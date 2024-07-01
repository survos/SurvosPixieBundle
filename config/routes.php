<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Survos\PixieBundle\Controller\PixieController;

return function (RoutingConfigurator $routes) {
    $routes->add('pixie_homepage', '/pixie/{pixieCode}')
        ->controller([PixieController::class, 'home'])
    ;
    $routes->add('pixie_import', '/pixie-import/{pixieCode}')
        ->controller([PixieController::class, 'import'])
    ;
    $routes->add('pixie_browse', '/pixie-browse/{pixieCode}/{tableName}')
        ->controller([PixieController::class, 'browse'])
    ;

    $routes->add('pixie_show_record', '/browse/{pixieCode}/{tableName}/{key}')
        ->controller([PixieController::class, 'show_record'])
    ;

};
