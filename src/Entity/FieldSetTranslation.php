<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use App\Entity\TranslatableFieldsInterface;
use App\Traits\TranslatableFieldsTrait;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TranslationInterface;
use Knp\DoctrineBehaviors\Model\Translatable\TranslationTrait;

#[ORM\Entity]
class  FieldSetTranslation implements TranslationInterface, TranslatableFieldsInterface
{
    use TranslationTrait;
    use TranslatableFieldsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    public function getId(): ?int
    {
        return $this->id;
    }


}
