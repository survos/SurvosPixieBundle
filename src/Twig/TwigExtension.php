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
            new TwigFilter('is_object', fn (mixed $s) => is_object($s)),
            new TwigFilter('is_array', fn (mixed $s) => is_array($s)),
            new TwigFilter('array_is_list', fn (mixed $s) => is_array($s) && array_is_list($s)),
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
            ]),
            new TwigFilter('urlize', fn($x, $target='blank', string $label=null) =>
            filter_var($x, FILTER_VALIDATE_URL)
                ? sprintf('<a target="%s" href="%s">%s</a>', $target, $x, $label ?: $x)
                : $x, [
                'is_safe' => ['html'],
            ]),
        ];
    }

    public function getFunctions(): array
    {
        return [
//            new TwigFunction('function_name', [::class, 'doSomething']),
        ];
    }
}
