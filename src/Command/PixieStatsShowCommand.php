<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\StatFacet;
use Survos\PixieBundle\Entity\StatProperty;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:stats:show', 'Show property usage and facet distributions')]
final class PixieStatsShowCommand
{
    public function __construct(private readonly PixieService $pixie) {}

    public function __invoke(SymfonyStyle $io, #[Argument] string $pixieCode, #[Argument] ?string $core = null): int
    {
        $ctx = $this->pixie->getReference($pixieCode);

        $io->title("Stats for $pixieCode".($core?" / $core":""));

        $propCriteria = ['owner_code'=>$pixieCode]; if ($core) $propCriteria['core']=$core;
        $facetCriteria= ['owner_code'=>$pixieCode]; if ($core) $facetCriteria['core']=$core;

        $props = $ctx->repo(StatProperty::class)->findBy($propCriteria, ['core'=>'ASC','property'=>'ASC']);
        $rows = [];
        foreach ($props as $p) {
            $rows[] = [$p->core, $p->property, $p->non_empty, $p->total];
        }
        $io->section('Property non-empty counts'); $io->table(['core','property','non_empty','total'],$rows);

        $facets = $ctx->repo(StatFacet::class)->findBy($facetCriteria, ['core'=>'ASC','property'=>'ASC','count'=>'DESC']);
        $rows = [];
        foreach ($facets as $f) {
            $rows[] = [$f->core, $f->property, $f->value, $f->count];
        }
        $io->section('Facet distributions'); $io->table(['core','property','value','count'],$rows);

        return 0;
    }
}
