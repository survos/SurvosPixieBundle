<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand('pixie:schema:dump', 'Dump the compiled schema (cores + fields) in text/json/yaml/md')]
final class PixieSchemaDumpCommand
{
    public function __construct(private readonly PixieService $pixie) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixieCode')] string $pixieCode,
        #[Option('core', 'c')] ?string $coreFilter = null,
        #[Option('format', 'f')] string $format = 'text',
        #[Option('out', 'o')] ?string $outPath = null
    ): int
    {
        if ($outPath) {
            $ext = strtolower(pathinfo($outPath, PATHINFO_EXTENSION));
            $format = match ($ext) {
                'json' => 'json', 'yml','yaml' => 'yaml', 'md' => 'md', default => $format,
            };
        }

        $ctx = $this->pixie->getReference($pixieCode);

        $criteria = ['ownerCode' => $pixieCode];
        if ($coreFilter) { $criteria['core'] = $coreFilter; }

        $cores = $ctx->repo(CoreDefinition::class)->findBy($criteria, ['core' => 'ASC']);
        if (!$cores) {
            $io->warning("No compiled schema found for '$pixieCode'".($coreFilter? " core '$coreFilter'":"").". Run: bin/console pixie:schema:sync $pixieCode");
            return 0;
        }

        $payload = ['pixie' => $pixieCode, 'cores' => []];
        foreach ($cores as $cd) {
            $fields = $ctx->repo(FieldDefinition::class)->findBy(
                ['ownerCode' => $pixieCode, 'core' => $cd->core],
                ['position' => 'ASC', 'id' => 'ASC']
            );
            $rows = [];
            foreach ($fields as $fd) {
                $rows[] = [
                    'code'         => $fd->code,
                    'kind'         => $fd->kind,
                    'targetCore'   => $fd->targetCore,
                    'delim'        => $fd->delim,
                    'translatable' =>method_exists($fd, 'isTranslatable') ? $fd->isTranslatable() : false,
                    'position'     => method_exists($fd, 'getPosition') ? $fd->getPosition() : null,
                ];
            }
            $payload['cores'][] = ['core' => $cd->core, 'pk' => $cd->pk, 'fields' => $rows];
        }

        // output
        if ($outPath) {
            $this->writeOut($payload, $format, $outPath, $io);
            return 0;
        }
        return match ($format) {
            'json' => $this->dumpJson($payload, $io),
            'yaml' => $this->dumpYaml($payload, $io),
            'md'   => $this->dumpMd($payload, $io),
            default => $this->dumpText($payload, $io),
        };
    }

    private function dumpJson(array $p, SymfonyStyle $io): int { $io->writeln(json_encode($p, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); return 0; }
    private function dumpYaml(array $p, SymfonyStyle $io): int { $io->writeln(Yaml::dump($p, 4, 2)); return 0; }

    private function dumpMd(array $p, SymfonyStyle $io): int
    {
        $io->writeln('# Pixie schema: '.$p['pixie']);
        foreach ($p['cores'] as $c) {
            $io->writeln("\n## `{$c['core']}` (pk: `{$c['pk']}`)\n");
            $io->writeln('| # | code | kind | target | delim | translatable |');
            $io->writeln('|:-:|:-----|:-----|:-------|:------|:-------------|');
            $i=1; foreach ($c['fields'] as $f) {
                $io->writeln(sprintf('| %d | `%s` | `%s` | `%s` | `%s` | %s |',
                    $i++, $f['code'], $f['kind']??'', $f['targetCore']??'', $f['delim']??'', ($f['translatable']?'yes':'no')));
            }
        }
        return 0;
    }

    private function dumpText(array $p, SymfonyStyle $io): int
    {
        $io->title('Pixie schema: '.$p['pixie']);
        foreach ($p['cores'] as $c) {
            $io->section(sprintf('%s (pk: %s)', $c['core'], $c['pk']));
            $rows=[]; $i=1;
            foreach ($c['fields'] as $f) {
                $rows[] = [$i++, $f['code'], $f['kind']??'', $f['targetCore']??'', $f['delim']??'', $f['translatable']?'yes':'no'];
            }
            $io->table(['#','code','kind','target','delim','translatable'], $rows);
        }
        return 0;
    }

    private function writeOut(array $p, string $fmt, string $path, SymfonyStyle $io): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create dir $dir");
        }
        $content = match ($fmt) {
            'json' => json_encode($p, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),
            'yaml' => Yaml::dump($p, 4, 2),
            'md'   => $this->capture(function() use ($p){
                $out=[]; $out[]='# Pixie schema: '.$p['pixie'];
                foreach ($p['cores'] as $c) {
                    $out[]=''; $out[]='## `'.$c['core'].'` (pk: `'.$c['pk'].'`)'; $out[]='';
                    $out[]='| # | code | kind | target | delim | translatable |';
                    $out[]='|:-:|:-----|:-----|:-------|:------|:-------------|';
                    $i=1; foreach ($c['fields'] as $f) {
                        $out[]=sprintf('| %d | `%s` | `%s` | `%s` | `%s` | %s |',
                            $i++, $f['code'], $f['kind']??'', $f['targetCore']??'', $f['delim']??'', ($f['translatable']?'yes':'no'));
                    }
                }
                return implode("\n",$out)."\n";
            }),
            default => $this->capture(function() use ($p){
                $buf=[]; $buf[]='Pixie schema: '.$p['pixie'];
                foreach ($p['cores'] as $c) {
                    $buf[]=''; $buf[]=sprintf('%s (pk: %s)', $c['core'], $c['pk']);
                    $i=1; foreach ($c['fields'] as $f) {
                        $buf[]=sprintf('  %2d. %-20s kind=%-14s target=%-10s delim=%-3s translatable=%s',
                            $i++, $f['code'], $f['kind']??'', $f['targetCore']??'', $f['delim']??'', $f['translatable']?'yes':'no');
                    }
                }
                return implode("\n",$buf)."\n";
            }),
        };
        file_put_contents($path, (string)$content);
        $io->success("Wrote $fmt schema to $path");
    }

    private function capture(callable $fn): string { return $fn(); }
}
