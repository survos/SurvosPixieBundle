<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Entity;

use App\Behavior\TranslationTrait;
use App\Entity\TranslatableFieldsInterface;
use App\Traits\TranslatableFieldsTrait;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TranslationInterface;

#[ORM\Entity]
class  InstanceTranslation implements TranslationInterface, TranslatableFieldsInterface
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
