<?php

namespace Survos\PixieBundle\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(
        private array $config,
        private ?RequestStack $requestStack=null,
//        #[Autowire('locale')] private string $locale
    )
    {

    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('file_exists', fn (string $s) => file_exists($s)),
            new TwigFilter('json_decode', fn (string $s, bool $asArray=true) => json_decode($s, $asArray)),
            new TwigFilter('t', function (object $obj, string $property) {
                $locale = $this->requestStack->getCurrentRequest()->getLocale();
                if ($_tr = $obj->_translations??null) {
                    return $_tr->{$locale}->{$property}??"$property-$locale-is-untranslated";
                } else {
//                    dd(obj: $obj);
                    return "translations-are-missing";
                }
            }, [
                'is_safe' => ['html'],
            ])
        ];
    }

    public function getFunctions(): array
    {
        return [
//            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }
}
