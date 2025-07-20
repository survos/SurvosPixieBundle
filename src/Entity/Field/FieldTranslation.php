<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Entity\Field;

use App\Behavior\TranslationTrait;
use App\Entity\TranslatableFieldsInterface;
use App\Traits\TranslatableFieldsTrait;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TranslationInterface;
use function Symfony\Component\String\u;

#[ORM\Entity]
class  FieldTranslation implements TranslationInterface, TranslatableFieldsInterface
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


    public static function getTranslatableEntityClass(): string
    {

        $translatableEntityClass = u(__CLASS__)->before('Translation')->toString();
        return Field::class;
//        return $translatableEntityClass;
        dd($translatableEntityClass);
        $explodedNamespace = explode('\\', __CLASS__);
        $entityClass = array_pop($explodedNamespace);
        // Remove Translation namespace
        array_pop($explodedNamespace);

        if (__CLASS__ == CategoryField::class) {
            dd($explodedNamespace);
        }

//        return Strings::substring(static::class, 0, -11);

        $translatableEntityClass =  '\\' . implode('\\', $explodedNamespace) . '\\' . substr($entityClass, 0, -11);
        dd($explodedNamespace, __CLASS__, $translatableEntityClass);
        return $translatableEntityClass;
    }

}
