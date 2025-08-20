<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Generic;

use Survos\PixieBundle\Dto\BaseObj;
use Survos\PixieBundle\Dto\Attributes\Map;
use Survos\PixieBundle\Dto\Attributes\Mapper;

/**
 * Generic mapper for common museum/object fields.
 */
#[Mapper(priority: 0)]
class Obj extends BaseObj
{
    #[Map(source: 'id')]
    public ?string $id = null;

    #[Map(source: 'title',   searchable: true, translatable: true)]
    public ?string $title = null;

    #[Map(source: 'description', searchable: true, translatable: true)]
    public ?string $description = null;

    #[Map(source: 'image')]
    public ?string $imageUrl = null;

    #[Map(source: 'nombre',  searchable: true, translatable: true)]
    public ?string $name = null;

    #[Map(source: 'clave_de_referencia')]
    public ?string $reference = null;

    // Facets:
    #[Map(source: 'nacionalidad', facet: true)]
    public ?string $nationality = null;

    #[Map(source: 'sexo',         facet: true)]
    public ?string $gender = null;

    #[Map(source: 'ocupacion',    facet: true)]
    public ?string $occupation = null;

    #[Map(source: 'estado_civil', facet: true)]
    public ?string $maritalStatus = null;

    // Places & dates (searchable where useful; years sortable)
    #[Map(source: 'lugar_de_nacimiento', searchable: true, translatable: true)]
    public ?string $birthPlace = null;

    #[Map(source: 'fecha_de_nacimiento')]
    public ?string $birthDate = null;

    #[Map(source: 'lugar_de_entrada', searchable: true, translatable: true)]
    public ?string $entryPlace = null;

    #[Map(source: 'fecha_de_entrada')]
    public ?string $entryDate = null;

    #[Map(searchable: true, translatable: true)]
    public ?string $label = null;

    #[Map(sortable: true)]
    public ?int $birthYear = null;

    #[Map(sortable: true)]
    public ?int $entryYear = null;

    public ?string $citation = null;

    public function afterMap(array &$norm, array $src): void
    {
        if (isset($norm['year']) && is_string($norm['year'])) {
            if (preg_match('/\b(\d{4})\b/', $norm['year'], $m)) {
                $norm['year'] = (int)$m[1];
                $this->year   = (int)$m[1];
            }
        }
    }
}
