<?php

namespace Survos\PixieBundle\Meta;


use Survos\PixieBundle\Entity\Row;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface HandlerInterface
{
    public function process(Row $row): Row;

    /**
     * Return null if invalid for some reason
     */
    public function prepare(array $row): ?array;

}
