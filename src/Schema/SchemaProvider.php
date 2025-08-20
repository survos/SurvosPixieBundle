<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Schema;

use Survos\PixieBundle\Import\Schema\CoreSchema;
use Survos\PixieBundle\Import\Schema\FieldSpec;
use Survos\PixieBundle\Import\Schema\FieldKind;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;

final class SchemaProvider
{
    public function __construct(
        private readonly YamlSchemaSynchronizer $sync,
        private readonly PixieService $pixie
    ) {}

    public function forCore(string $owner, string $core, ?Config $fallback = null): CoreSchema
    {
        $ctx = $this->pixie->getReference($owner);

        /** @var CoreDefinition|null $cd */
        $cd = $ctx->repo(CoreDefinition::class)->findOneBy(['ownerCode' => $owner, 'core' => $core]);
        /** @var FieldDefinition[] $fields */
        $fields = $ctx->repo(FieldDefinition::class)->findBy(['ownerCode' => $owner, 'core' => $core], ['position' => 'ASC', 'id' => 'ASC']);

        if (!$cd || !$fields) {
            if ($fallback) {
                $this->sync->sync($owner, $fallback);
                $cd = $ctx->repo(CoreDefinition::class)->findOneBy(['ownerCode' => $owner, 'core' => $core]);
                $fields = $ctx->repo(FieldDefinition::class)->findBy(['ownerCode' => $owner, 'core' => $core], ['position' => 'ASC', 'id' => 'ASC']);
            }
        }
        if (!$cd || !$fields) {
            throw new \RuntimeException("No schema for {$owner}/{$core}");
        }

        $map = [];
        foreach ($fields as $fd) {
            $kind = match ($fd->kind) {
                'label'         => FieldKind::Label,
                'json_scalar'   => FieldKind::JsonScalar,
                'json_array'    => FieldKind::JsonArray,
                'relation_one'  => FieldKind::RelationOne,
                'relation_many' => FieldKind::RelationMany,
                default         => FieldKind::Ignored,
            };
            $map[$fd->incomingHeader] = new FieldSpec(
                code:       $fd->code,
                kind:       $kind,
                targetCore: $fd->targetCore,
                delim:      $fd->delim,
                caster:     null
            );
        }

        return new CoreSchema($cd->core, $cd->pk, $map, $cd->schemaVersion);
    }
}
