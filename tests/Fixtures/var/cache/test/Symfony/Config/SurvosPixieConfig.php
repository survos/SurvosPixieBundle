<?php

namespace Symfony\Config;

use Symfony\Component\Config\Loader\ParamConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * This class is automatically generated to help in creating a config.
 */
class SurvosPixieConfig implements \Symfony\Component\Config\Builder\ConfigBuilderInterface
{
    private $extension;
    private $dbDir;
    private $dataDir;
    private $configDir;
    private $_usedProperties = [];

    /**
     * the pixie db extension
     * @default '.pixie.db'
     * @param ParamConfigurator|mixed $value
     * @return $this
     */
    public function extension($value): static
    {
        $this->_usedProperties['extension'] = true;
        $this->extension = $value;

        return $this;
    }

    /**
     * where to store the pixie db files
     * @default './pixie]'
     * @param ParamConfigurator|mixed $value
     * @return $this
     */
    public function dbDir($value): static
    {
        $this->_usedProperties['dbDir'] = true;
        $this->dbDir = $value;

        return $this;
    }

    /**
     * where to look for csv/json data
     * @default './data'
     * @param ParamConfigurator|mixed $value
     * @return $this
     */
    public function dataDir($value): static
    {
        $this->_usedProperties['dataDir'] = true;
        $this->dataDir = $value;

        return $this;
    }

    /**
     * location of .pixie.yaml config files
     * @default './pixie'
     * @param ParamConfigurator|mixed $value
     * @return $this
     */
    public function configDir($value): static
    {
        $this->_usedProperties['configDir'] = true;
        $this->configDir = $value;

        return $this;
    }

    public function getExtensionAlias(): string
    {
        return 'survos_pixie';
    }

    public function __construct(array $value = [])
    {
        if (array_key_exists('extension', $value)) {
            $this->_usedProperties['extension'] = true;
            $this->extension = $value['extension'];
            unset($value['extension']);
        }

        if (array_key_exists('db_dir', $value)) {
            $this->_usedProperties['dbDir'] = true;
            $this->dbDir = $value['db_dir'];
            unset($value['db_dir']);
        }

        if (array_key_exists('data_dir', $value)) {
            $this->_usedProperties['dataDir'] = true;
            $this->dataDir = $value['data_dir'];
            unset($value['data_dir']);
        }

        if (array_key_exists('config_dir', $value)) {
            $this->_usedProperties['configDir'] = true;
            $this->configDir = $value['config_dir'];
            unset($value['config_dir']);
        }

        if ([] !== $value) {
            throw new InvalidConfigurationException(sprintf('The following keys are not supported by "%s": ', __CLASS__).implode(', ', array_keys($value)));
        }
    }

    public function toArray(): array
    {
        $output = [];
        if (isset($this->_usedProperties['extension'])) {
            $output['extension'] = $this->extension;
        }
        if (isset($this->_usedProperties['dbDir'])) {
            $output['db_dir'] = $this->dbDir;
        }
        if (isset($this->_usedProperties['dataDir'])) {
            $output['data_dir'] = $this->dataDir;
        }
        if (isset($this->_usedProperties['configDir'])) {
            $output['config_dir'] = $this->configDir;
        }

        return $output;
    }

}
