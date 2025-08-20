<?php
// packages/pixie-bundle/src/EventListener/PixiePostLoadListener.php
declare(strict_types=1);

namespace Survos\PixieBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\TranslationResolver;

#[AsDoctrineListener(event: Events::postLoad)]
final class PixiePostLoadListener
{
    public function __construct(
        private readonly LocaleContext $locale,
        private readonly TranslationResolver $resolver,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TranslatableByCodeInterface) {
            return;
        }

        $codeMap = $entity->getStrCodeMap();             // field => code
        if (!$codeMap) return;

        $codes = array_values(array_unique(array_filter($codeMap)));
        if (!$codes) return;

        $texts = $this->resolver->textsFor($codes, $this->locale->get()); // code => text

        foreach ($codeMap as $field => $code) {
            if (!$code) continue;
            $entity->setResolvedTranslation($field, $texts[$code] ?? '');
        }
    }
}
