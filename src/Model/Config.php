<?php

namespace Survos\PixieBundle\Model;

use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Model\Table;
use Symfony\Component\Yaml\Yaml;

class Config
{
    const string TYPE_SYSTEM = 'system';
    const string TYPE_MUSEUM = 'museum';
    const string VISIBILITY_PUBLIC = 'public';
    const string VISIBILITY_PRIVATE = 'private';
    const string VISIBILITY_UNLISTED = 'unlisted';
    const string TYPE_AGGREGATOR = 'agg';

    /**
     * @param array<string, Table> $tables
     */
    public function __construct(
        private readonly string|float|null $version=null,
        public ?string $code=null,
        public ?Source $source=null,
        private array $files=[],
        /** @var array<string,Table> */ public array $tables = [],
        public array $templates=[],
        private ?string $configFilename=null,
        private readonly string $type=self::TYPE_MUSEUM,
        private string $visibility=self::VISIBILITY_PUBLIC,
        private readonly array           $data=[],
        public ?string $dataDir = null, // set in service, kinda hacky
        public ?string $pixieFilename = null, // set in service, kinda hacky, the sqlite file
        private ?Owner $owner=null,

    )
    {
//        if ($this->$configFilename) {
//            $x = $denormalizer->denormalize($configData, Config::class);
//            dd($x, $configData);
//
//            $this->data = Yaml::parseFile($this->filename);
//        }
    }

    public function getOwner(): ?Owner
    {
        return $this->owner;
    }

    public function setOwner(?Owner $owner): Config
    {
        $this->owner = $owner;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getSource(): ?Source
    {
        return $this->source;
    }

    public function getDataDir(): ?string
    {
        return $this->dataDir;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getPixieFilename(): ?string
    {
        return $this->pixieFilename;
    }

    public function setPixieFilename(?string $pixieFilename): self
    {
        $this->pixieFilename = $pixieFilename;
        return $this;
    }

    public function getConfigFilename(): ?string
    {
        return $this->configFilename;
    }
    public function setConfigFilename(?string $configFilename): self
    {
        $this->configFilename = $configFilename;
        return $this;
    }

    public function getIgnored(): array
    {
        $ignore =  $this->source?->ignore;
        if (is_string($ignore)) {
            $ignore = [$ignore];
        }
        return $ignore;
    }
    public function getFileToTableMap(): array
    {
        return $this->files;
    }

    public function getSourceFilesDir(): ?string
    {
        return $this->source?->dir;
    }

    /**
     * @return array<Table>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTable(string $tableName): ?Table
    {
        return $this->tables[$tableName] ?? null;
    }

    public function addTable(string $tableName, Table $table): self
    {
        $this->tables[$tableName] = $table;
        return $this;
    }

    public function setTables(array $tables): Config
    {
        foreach ($tables as $table) {
            assert($table instanceof Table, "should be a table!");
        }
        $this->tables = $tables;
        return $this;
    }

    public function setFiles(array $files): Config
    {
        $this->files = $files;
        return $this;
    }

    public function setSource(?Source $source): Config
    {
        $this->source = $source;
        return $this;
    }

    public function getTableRules($tableName): array
    {
        return $this->tables[$tableName]->getRules();
    }
    public function getProperties($tableName): array
    {

        return $this->tables[$tableName]->getProperties();
    }

    public function getVersion(): string|float|int
    {
        assert($this->version, "Missing version in $this->pixieFilename, $this->code");
        return $this->version;
    }

    public function rp()
    {
        return ['pixieCode' => $this->code];
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }
    public function isMuseum(): bool
    {
        return $this->type === self::TYPE_MUSEUM;
    }

}
