<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Controller;

use Survos\PixieBundle\Service\DtosToMeiliSettings;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pixie')]
final class PixieMeiliController extends AbstractController
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly DtosToMeiliSettings $settingsBuilder
    ) {}

    #[Route('/{pixieCode}/meili/settings', name: 'pixie_meili_settings')]
    #[Template('@SurvosPixie/pixie/meili_settings.html.twig')]
    public function settings(string $pixieCode, Request $request): array
    {
        $ctx    = $this->pixie->getReference($pixieCode);
        $core   = $request->query->get('core', 'obj');
        $locale = $request->query->get('locale', $ctx->config->getSource()?->locale ?? 'en');

        $settings = $this->settingsBuilder->build($pixieCode, $core);

        // Always filter by "core" in queries; keep it filterable
        if (!\in_array('core', $settings['filterableAttributes'], true)) {
            array_unshift($settings['filterableAttributes'], 'core');
        }

        // You might add 'lang' if you ever collapse locales into one index
        // if (!\in_array('lang', $settings['filterableAttributes'], true)) { $settings['filterableAttributes'][] = 'lang'; }

        return [
            'pixieCode' => $pixieCode,
            'core'      => $core,
            'locale'    => $locale,
            'settings'  => $settings,
            'conf'      => $ctx->config,
        ];
    }
}
