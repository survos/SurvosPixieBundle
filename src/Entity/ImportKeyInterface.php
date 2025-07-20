<?php

namespace Survos\PixieBundle\Entity;

interface ImportKeyInterface
{
    public function getImportFields(): array;

    public function getImportFieldsAsString(): string;

    public function setImportFields(array $importFields): self;

    public function addImportField(string $importField): self;
}
