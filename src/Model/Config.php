<?php

namespace Survos\PixieBundle\Model;

use Symfony\Component\Yaml\Yaml;

class Config
{
    public function __construct(
        private readonly string $filename,
        private array           $data=[],
    )
    {
        $this->data = Yaml::parseFile($this->filename);
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getDataDirectory(): ?string
    {
        return $this->data['source']['dir']??null;
    }

    public function getVersion(): string
    {
        return $this->data['version'];

    }


}
