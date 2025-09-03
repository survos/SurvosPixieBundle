<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Dto\Attributes\Mapper as MapperAttr;
use Survos\PixieBundle\Dto\Attributes\Map as MapAttr;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use ReflectionClass;
use ReflectionProperty;

/**
 * Registry of DTO mappers discovered via tag "pixie.dto".
 * Ordered by tag priority (desc).
 */
final class DtoRegistry
{
    /** @var list<array{class:class-string, priority:int, when:string[], except:string[], cores:string[]}> */
    public array $entries = [];

    /**
     * @param iterable<object> $dtos DTO services, tagged with pixie.dto
     */
    public function __construct(
        #[AutowireIterator('pixie.dto', defaultPriorityMethod: null)]
        iterable $dtos
    ) {
        // Note: TaggedIterator preserves priority ordering automatically
        foreach ($dtos as $dto) {
            $class = \get_class($dto);
            $meta  = $this->readMapperMeta($class);
            $this->entries[] = [
                'class'    => $class,
                'priority' => $meta['priority'],
                'when'     => $meta['when'],
                'except'   => $meta['except'],
                'cores'    => $meta['cores'],
            ];
        }
    }

    /** @return array{class:class-string|null, score:int} */
    public function select(string $pixie, string $core, array $record): array
    {
        $best = [null, 0];
        foreach ($this->entries as $e) {
            if ($e['when']   && !\in_array($pixie, $e['when'], true)) continue;
            if ($e['except'] &&  \in_array($pixie, $e['except'], true)) continue;
            if ($e['cores']  && !\in_array($core,  $e['cores'], true)) continue;

            $score = $this->score($e['class'], $record, $pixie, $e['priority']);
            if ($score > $best[1]) {
                $best = [$e['class'], $score];
            }
        }
        return ['class' => $best[0], 'score' => $best[1]];
    }

    // ... inside DtoRegistry
    /** @return list<array{class:class-string, score:int}> */
    public function rank(string $pixie, string $core, array $record): array
    {
        $scored = [];
        foreach ($this->entries as $e) {
            if ($e['when']   && !in_array($pixie, $e['when'], true)) continue;
            if ($e['except'] &&  in_array($pixie, $e['except'], true)) continue;
            if ($e['cores']  && !in_array($core,  $e['cores'], true)) continue;
            $score = $this->score($e['class'], $record, $pixie, $e['priority']);
            if ($score > 0) {
                $scored[] = ['class' => $e['class'], 'score' => $score];
            }
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    /** expose the entries if you need them elsewhere */
    public function entries(): array { return $this->entries; }


    /** @return array{priority:int, when:string[], except:string[], cores:string[]} */
    private function readMapperMeta(string $class): array
    {
        $rc = new ReflectionClass($class);
        $attrs = $rc->getAttributes(MapperAttr::class);
        if (!$attrs) return ['priority'=>10,'when'=>[],'except'=>[],'cores'=>[]];
        /** @var MapperAttr $m */
        $m = $attrs[0]->newInstance();
        return ['priority'=>$m->priority, 'when'=>$m->when, 'except'=>$m->except, 'cores'=>$m->cores];
    }

    private function score(string $class, array $record, string $pixie, int $base): int
    {
        $rc = new ReflectionClass($class);
        $score = $base;
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(MapAttr::class);
            if (!$attrs) continue;
            /** @var MapAttr $map */
            $map = $attrs[0]->newInstance();

            // honor when/except on Map fields
            if ($map->when   && !\in_array($pixie, $map->when, true))   continue;
            if ($map->except &&  \in_array($pixie, $map->except, true)) continue;

            if ($map->source !== null && \array_key_exists($map->source, $record)) {
                $score += max(1, $map->priority);
                continue;
            }
            if ($map->regex !== null) {
                $pattern = \str_starts_with($map->regex, '/') ? $map->regex : '/'.$map->regex.'/i';
                foreach ($record as $k => $_) {
                    if (@\preg_match($pattern, (string)$k) && \preg_match($pattern, (string)$k)) {
                        $score += max(1, $map->priority);
                        break;
                    }
                }
                continue;
            }
            if (\array_key_exists($prop->getName(), $record)) {
                $score += max(1, $map->priority);
            }
        }
        return $score;
    }
}
