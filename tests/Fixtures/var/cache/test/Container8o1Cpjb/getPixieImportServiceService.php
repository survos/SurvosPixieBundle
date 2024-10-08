<?php

namespace Container8o1Cpjb;

use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * @internal This class has been auto-generated by the Symfony Dependency Injection Component.
 */
class getPixieImportServiceService extends Survos_PixieBundle_Tests_Fixtures_TestKernelTestDebugContainer
{
    /**
     * Gets the private 'Survos\PixieBundle\Service\PixieImportService' shared autowired service.
     *
     * @return \Survos\PixieBundle\Service\PixieImportService
     */
    public static function do($container, $lazyLoad = true)
    {
        include_once \dirname(__DIR__, 6).'/src/Service/PixieImportService.php';

        return $container->privates['Survos\\PixieBundle\\Service\\PixieImportService'] = new \Survos\PixieBundle\Service\PixieImportService(($container->privates['Survos\\PixieBundle\\Service\\PixieService'] ?? $container->load('getPixieServiceService')), ($container->privates['logger'] ?? self::getLoggerService($container)), ($container->services['event_dispatcher'] ?? self::getEventDispatcherService($container)));
    }
}
