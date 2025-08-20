<?php
namespace Survos\PixieBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\LibreTranslateBundle\Service\TranslationClientService;
use Survos\PixieBundle\Repository\StrRepository;
use Survos\PixieBundle\Repository\StrTranslationRepository;
use Survos\WorkflowBundle\Traits\MarkingInterface;
use Survos\WorkflowBundle\Traits\MarkingTrait;

#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\Table()]
#[ORM\Index(name: 'idx_tr_locale', columns: ['locale'])]
class StrTranslation implements \Stringable, MarkingInterface
{
    use MarkingTrait;
    // composite key (str_code, locale)
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Str::class)]
    #[ORM\JoinColumn(name: 'str_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    private Str $str_ref;

    #[ORM\Id]
    #[ORM\Column(name: 'locale', length: 15)]
    private string $locale_raw;

    #[ORM\Column(name: 'value', type: Types::TEXT)]
    private string $value_raw;

    #[ORM\Column(name: 'value_hash', length: 64)]
    private string $value_hash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $created_at;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updated_at;

    public function __construct(Str $str, string $locale, string $value)
    {
        $this->str_ref    = $str;
        $this->locale_raw = $locale;
        $this->value      = $value; // go through hook
        $now = new \DateTimeImmutable();
        $this->created_at = $now;
        $this->updated_at = $now;
    }

    private function touch(): void { $this->updated_at = new \DateTimeImmutable(); }

    // Virtuals

    public Str $str { get => $this->str_ref; }
    public string $code { get => $this->str_ref->code; }   // convenience

    public string $locale { get => $this->locale_raw; }

    public string $value {
        get => $this->value_raw;
        set { $this->value_raw = $value; $this->value_hash = TranslationClientService::calcHash($value, $this->locale_raw); $this->touch(); }
    }

    public function __toString()
    {
        return mb_substr($this->value, 0, 60);
    }
}
