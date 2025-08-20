<?php
// packages/pixie-bundle/src/Entity/Traits/TranslatableFieldsByCode.php
declare(strict_types=1);

namespace Survos\PixieBundle\Entity\Traits;

use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Entity\Str;

/**
 * Store per-field Str codes and expose translated strings via a helper.
 * Persistence strategy is up to the using class (see example below).
 */
trait TranslatableFieldsByCode
{
    /** @var array<string,string> NOT persisted by the trait */
    private array $str_code_map = [];

    /** @var array<string,string> NOT persisted: field => translated text for the current request/run */
    private array $resolved_strings = [];

    /**
     * Bind a field to a Str code. Accepts Str or the code string.
     */
    public function bindTranslatable(string $field, Str|string $strOrCode): void
    {
        $code = $strOrCode instanceof Str ? $strOrCode->code : (string)$strOrCode;
        $this->str_code_map[$field] = $code;
    }

    /**
     * Listener will call this after batch-resolving translations.
     */
    public function setResolvedTranslation(string $field, ?string $value): void
    {
        $this->resolved_strings[$field] = $value ?? '';
    }

    /** Debug only? */
    public function getResolvedStrings(): array
    {
        return $this->resolved_strings;
    }

    /**
     * Read helper for property hooks in your entity:
     *   public string $label { get => $this->translated('label'); }
     */
    protected function translated(string $field): string
    {
        return $this->resolved_strings[$field] ?? '';
    }

    /** Debug/utility */
    public function getStrCodeFor(string $field): ?string
    {
        return $this->str_code_map[$field] ?? null;
    }

    // --- Interface support ---

    public function getStrCodeMap(): array
    {
        return $this->str_code_map;
    }

    public function setStrCodeMap(?array $map): static
    {
        $this->str_code_map = $map ? array_filter($map, fn($v) => (string)$v !== '') : [];
        return $this;
    }
}
