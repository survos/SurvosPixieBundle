<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Dto\Cleveland;

use Survos\PixieBundle\Dto\BaseObj;
use Survos\PixieBundle\Dto\Attributes\Map;
use Survos\PixieBundle\Dto\Attributes\Core;

/**
 * Example museum DTO: map various vendor keys → normalized fields.
 * Apps may create their own DTOs extending BaseObj in their own namespaces.
 */
class Obj extends BaseObj
{
    #[Map(source: 'url_id')]
    public ?string $id = null;

    public ?string $tombstone = null;
    public object $images;
    public array $creators = [];

    #[Map(regex: '/credit|citation/i')]
    public ?string $citation = null;

    #[Map(regex: '/place|ubicacion|location/i')]
    public ?string $currentLocation = null;

    #[Map(if: 'isset')]
    public ?object $meas = null;

    #[Core(target: 'cul', multiple: true)]
    public ?array $cul = null;

    #[Core(target: 'tec')]
    public ?string $tec = null;

    #[Core(target: 'department')]
    public ?string $department = null;

    public ?int $year = null;

    #[Map(regex: '/themes|keywords/i', delim: '|')]
    public array $keywords = [];

    public function afterMap(array &$norm, array $src): void
    {
        if (!$this->year && isset($src['date_created'])) {
            if (preg_match('/\b(\d{4})\b/', (string)$src['date_created'], $m)) {
                $this->year = (int)$m[1];
            }
        }
    }
}
