<?php

namespace ContainerKoSCo4l;

use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * @internal This class has been auto-generated by the Symfony Dependency Injection Component.
 */
class getPixieServiceService extends Survos_PixieBundle_Tests_Fixtures_TestKernelTestDebugContainer
{
    /**
     * Gets the private 'Survos\PixieBundle\Service\PixieService' shared autowired service.
     *
     * @return \Survos\PixieBundle\Service\PixieService
     */
    public static function do($container, $lazyLoad = true)
    {
        include_once \dirname(__DIR__, 6).'/src/Service/PixieService.php';

        return $container->privates['Survos\\PixieBundle\\Service\\PixieService'] = new \Survos\PixieBundle\Service\PixieService(true, [], 'pixie.db', './', './', './', \dirname(__DIR__, 4), ($container->privates['logger'] ?? self::getLoggerService($container)), ($container->services['debug.stopwatch'] ??= new \Symfony\Component\Stopwatch\Stopwatch(true)), ($container->privates['property_accessor'] ?? $container->load('getPropertyAccessorService')));
    }
}
