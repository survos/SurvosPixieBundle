<?php

namespace Survos\PixieBundle\Components;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PreMount;
use Twig\Environment;

#[AsTwigComponent(name: 'pixie:db', template: '@SurvosPixie/components/db.html.twig')]
class DatabaseComponent
{
    public string $pixieCode;
    public ?Config $config;
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private PixieService $pixieService,
    )
    {
    }

    public function mount(string $pixieCode): void
    {
        $this->config = $this->pixieService->getConfig($pixieCode);
    }

    #[PreMount]
    public function preMount(array $parameters = []): array
    {
        return $parameters;
    }



}
