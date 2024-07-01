<?php

/** generated from /home/tac/ca/survos/packages/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\PixieBundle;

use Survos\ApiGrid\Controller\GridController;
use Survos\PixieBundle\Controller\PixieController;
use Survos\PixieBundle\DataCollector\PixieDataCollector;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\EventListener\CsvHeaderEventListener;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\SqliteService;
use Survos\PixieBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Twig\Environment;

class SurvosPixieBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->register(PixieImportService::class)
            ->setAutowired(true)
        ;

        if (class_exists(Environment::class)) {
            $builder
                ->setDefinition('survos.pixie_bundle', new Definition(TwigExtension::class))
                ->setArgument('$config', $config)
                ->addTag('twig.extension')
                ->setPublic(false);
        }


        $builder->autowire(SqliteService::class)
            ->setAutowired(true)
            ->setPublic(true);

        $builder->autowire(PixieController::class)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments')
            ->setArgument('$chartBuilder', new Reference('chartjs.builder', ContainerInterface::NULL_ON_INVALID_REFERENCE))
//            ->setAutoconfigured(true)
//            ->setAutowired(true)
//            ->setPublic(true)
        ;

        $builder->autowire(PixieDataCollector::class)
            ->setArgument('$pixieService', new Reference(PixieService::class))
//            ->setArgument('$logger', new Reference('logger'))
            ->addTag('data_collector', [
                'template' => '@SurvosPixie/DataCollector/pixie_debug_profile.html.twig'
            ]);


        // storageBoxService, right?  Then get an instance of the storageBox? PixieService?
        foreach ([StorageBox::class, TraceableStorageBox::class] as $storageBoxClass) {
            $builder->register($storageBoxClass)
                ->setAutowired(true)
                ->setArgument('$logger', new Reference('logger'))
            ;

        }

        $x = $builder->register(PixieService::class)
            ->setAutowired(true)
            ->setArgument('$isDebug', $builder->getParameter('kernel.debug'))
            ->setArgument('$dataRoot', $config['data_root'])
            ->setArgument('$configDir', $config['config_dir'])
            ->setArgument('$dbDir', $config['db_dir'])
            ->setArgument('$stopwatch', new Reference('debug.stopwatch'))
            ->setArgument('$logger', new Reference('logger'))
        ;

        // register our listener.  We could disable or set priority in the config
        $builder->register(CsvHeaderEventListener::class)
            ->addTag('kernel.event_listener', [
                'method' => 'onCsvHeaderEvent',
                'event' => CsvHeaderEvent::class])
            ->setAutowired(true)
        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('extension')->info("the pixie db extension")->defaultValue('.pixie.db')->end()
            ->scalarNode('db_dir')->info("where to store the pixie db files")->defaultValue('pixie]')->end()
            ->scalarNode('data_root')->info("root for csv/json data")->defaultValue('data')->end()
            ->scalarNode('config_dir')->info("location of .pixie.yaml config files")->defaultValue('config/packages/pixie')->end()
            ->end();
    }
}
