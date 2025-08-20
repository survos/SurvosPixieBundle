<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;

/**
 * Builds a view-friendly structure of the compiled schema
 * (CoreDefinition + FieldDefinition) using ctx->repo() and public properties.
 */
final readonly class SchemaViewService
{
    public function __construct(private PixieService $pixie) {}

    /**
     * @return array{
     *   pixie: string,
     *   coreCount: int,
     *   cores: list<array{
     *     core: string,
     *     pk: string,
     *     fieldCount: int,
     *     fields: list<array{
     *       code: string,
     *       kind: ?string,
     *       targetCore: ?string,
     *       delim: ?string,
     *       translatable: bool,
     *       position: int|null
     *     }>
     *   }>
     * }
     */
    public function getCompiledSchema(string $pixieCode, string|Core|null $coreFilter = null): array
    {
        $ctx = $this->pixie->getReference($pixieCode);

        $criteria = ['ownerCode' => $pixieCode];
        if ($coreFilter) {
            $criteria['core'] = $coreFilter;
        }

        /** @var list<CoreDefinition> $cores */
        $cores = $ctx->repo(CoreDefinition::class)->findBy($criteria, ['core' => 'ASC']);

        $outCores = [];
        foreach ($cores as $cd) {
            // public properties (property hooks supported)
            $coreName = $cd->core ?? $cd->getCore();
            $pk       = $cd->pk   ?? ($cd->getPk() ?? 'id');

            /** @var list<FieldDefinition> $fields */
            $fields = $ctx->repo(FieldDefinition::class)->findBy(
                ['ownerCode' => $pixieCode, 'core' => $coreName],
                ['position' => 'ASC', 'id' => 'ASC']
            );

            $rows = [];
            foreach ($fields as $fd) {
                // read public props; fallback to methods if needed
                $rows[] = [
                    'code'         => $fd->code ,
                    'kind'         => $fd->kind ,
                    'targetCore'   => $fd->targetCore,
                    'delim'        => $fd->delim,
                    'translatable' => property_exists($fd, 'translatable')
                        ? (bool)$fd->translatable
                        : (method_exists($fd, 'isTranslatable') ? (bool)$fd->isTranslatable() : false),
                    'position'     => property_exists($fd, 'position')
                        ? $fd->position
                        : (method_exists($fd, 'getPosition') ? $fd->getPosition() : null),
                ];
            }

            $outCores[] = [
                'core'       => $coreName,
                'pk'         => $pk,
                'fieldCount' => \count($rows),
                'fields'     => $rows,
            ];
        }

        return [
            'pixie'     => $pixieCode,
            'coreCount' => \count($outCores),
            'cores'     => $outCores,
        ];
    }
}
