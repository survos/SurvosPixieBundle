<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\PixieBundle\Repository\StrTranslationRepository;

#[ORM\Entity(StrTranslationRepository::class)]
#[ORM\Table(name: 'str_translation')]
class StrTranslation extends StrTranslationBase {}
