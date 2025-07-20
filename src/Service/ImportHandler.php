<?php

namespace Survos\PixieBundle\Service;

use Psr\Container\ContainerInterface;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Meta\HandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class ImportHandler
{

    public function __construct(
        #[AutowireLocator(HandlerInterface::class, indexAttribute: 'keys')]
        private ContainerInterface $handlers,
//        private ServiceCollectionInterface $handlers,

//        #[AutowireIterator(HandlerInterface::class, indexAttribute: 'key')]
//        private iterable $handlers,
    )
    {
    }


    public function getHandler(string $key): ?HandlerInterface
    {
        dd($this->handlers->get($key));
        dd($this->handlers);
        foreach ($this->handlers as $name=>$handler) {
            dd($name, $handler);
            if ($name === $key) {
                return $handler;
            }
        }
        assert(false, "Missing $key handler");
        return null;
    }
    /**
     * @return string[]
     */
//    public function handlers(): iterable
//    {
////        return array_keys($this->handlers->getProvidedServices());
//        return array_keys($this->handlers);
//    }

    public function process(string $configCode, Row $row): Row
    {
        $handler =  $this->getHandler($configCode); // $this->handlers->get($configCode);
        return $handler->process($row);
    }

    public function prepare(string $configCode, array $row): ?array
    {
//        dump($this->handlers);
//        $handler =  $this->handlers->get($configCode);
        $handler =  $this->getHandler($configCode); // $this->handlers->get($configCode);
        dd($handler, $handler::class);
        return $handler->prepare($row);
    }
}
