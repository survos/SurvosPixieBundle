<?php
namespace Survos\PixieBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\LibreTranslateBundle\Service\TranslationClientService;
use Survos\PixieBundle\Repository\StrRepository;
use Survos\PixieBundle\Service\PixieService;

#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Table()]
#[ORM\Index(name: 'idx_str_original_hash', columns: ['original_hash'])]
class Str implements \Stringable
{
    // Backing fields (mapped)
    #[ORM\Id]
    #[ORM\Column(name: 'code', length: 64)]
    private(set) string $code_pk;

    #[ORM\Column(name: 'original', type: Types::TEXT)]
    private string $original_raw;

    #[ORM\Column(name: 'src_locale', length: 10)]
    private string $src_locale_raw = 'en';

    // Denormalized per-locale translations (truth lives in StrTranslation)
    #[ORM\Column(name: 't', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $t_raw = null;

    #[ORM\Column(name: 'original_hash', length: 64)]
    private string $original_hash;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $created_at;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updated_at;

    public function __construct(string $code, string $original, string $srcLocale = 'en')
    {
        $this->code_pk        = $code;
        $this->original_raw   = $original;
        $this->src_locale_raw = $srcLocale;
        $this->original_hash  = TranslationClientService::calcHash($original, $srcLocale);
        $this->created_at     = new \DateTimeImmutable();
        $this->updated_at     = $this->created_at;
    }


    private function touch(): void { $this->updated_at = new \DateTimeImmutable(); }

    // Virtuals (unmapped) — property hooks

    // id (read-only)
    public string $code { get => $this->code_pk; }

    // source text
    public string $original {
        get => $this->original_raw;
        set {
            $this->original_raw  = $value;
            $this->original_hash = TranslationClientService::calcHash($value, $this->src_locale_raw);
            $this->touch();
        }
    }

    // convenient alias
    public string $text { get => $this->original_raw; }

    public string $srcLocale {
        get => $this->src_locale_raw;
        set { $this->src_locale_raw = $value; $this->touch(); }
    }

    // cache of translations (locale => string)
    public array $t {
        get => $this->t_raw ?? [];
        set { $this->t_raw = (array)$value ?: null; $this->touch(); }
    }

    // tiny helpers
    public int $originalLength { get => \strlen($this->original_raw); }
    public array $translatedLocales { get => \array_keys($this->t_raw ?? []); }

    // (optional) legacy method shims
    public function getCode(): string { return $this->code; }
    public function getOriginal(): string { return $this->original; }
    public function setOriginal(string $v): self { $this->original = $v; return $this; }

    public function __toString()
    {
        return substr($this->code, 0, 5) . ':' . mb_substr($this->original, 0, 100);
    }
}
