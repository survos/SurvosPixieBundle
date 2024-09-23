<?php

namespace Survos\PixieBundle\CsvSchema;

use Illuminate\Support\Collection;
use League\Csv\Reader;
use Survos\PixieBundle\CsvSchema\Exceptions\CastException;
use Survos\PixieBundle\CsvSchema\Exceptions\UnsupportedTypeException;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Model\Schema;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Yaml\Yaml;
use function Symfony\Component\String\u;

/**
 * CSV Parser class. This is where the magic happens.
 *
 * @author K.Sassnowski <ksassnowski@gmail.com>
 *
 * from https://github.com/shakahl/csv-schema
 */
class Parser
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var string
     */
    private $defaultDelimiter = ',';

    /**
     * @var string
     */
    private $defaultEnclosure = '"';

    /**
     * @var string
     */
    private $defaultEscape = '\\';

    /**
     * @var string
     */
    private $defaultEncoding = 'UTF-8';
    /**
     * @var bool
     */
    private $skipTitle = false;

    /**
     * @var array
     */
    private static $customTypes = [];

    /**
     * Parser constructor.
     */
    public function __construct(private Schema $schema)
    {
    }

    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * Register a handler for a custom type. The handler will be called with
     * the value to parse.
     *
     * @param string   $type
     * @param callable $callback
     */
    public static function registerType($type, callable $callback): void
    {
        static::$customTypes[$type] = $callback;
    }

    // https://stackoverflow.com/questions/22539633/parse-string-containing-dots-in-php
    // since parse_str can't handle .

    static public function parseQueryString($data): array
    {
        $data = preg_replace_callback('/(?:^|(?<=&))[^=[]+/', function ($match) {
            return bin2hex(urldecode($match[0]));
        }, $data);

        parse_str($data, $values);

        return array_combine(array_map('hex2bin', array_keys($values)), $values);
    }


    /**
     * @param $input
     *
     * @return array
     */
    public function fromString($input)
    {
        assert(class_exists(Reader::class),"missing Reader::class");
        return $this->parse(Reader::createFromString($input));
    }

    public function fromFile(string $filename): \Generator
    {
        return $this->parse(Reader::createFromPath($filename));
    }

    static public function createConfigFromMap(array $map=[], $headers=[]): Schema    {
        $schema = new Schema();
        // create the map from the headers\
        foreach ($headers as $idx => $header) {
            $property = Parser::parseConfigHeader($header, u($header)->snake()->toString());
//            dd($property, $column);
            // if the last character is a symbol, process it
//            if (str_contains($column, ':')) {
//                [$newColumn, $rule] = explode(':', $column);
//                $map["/^$newColumn$/"] = $rule;
//            } else {
//                $newColumn = ; // default
//            }
//
//            $lastChar = substr($newColumn, -1);
//            if (in_array($lastChar, ['|', '$', ',', '/'])) {
//                $newColumn = rtrim($column, $lastChar);
//                $map["/^$newColumn$/"] = "array($lastChar)";
//
//            }
            $columns[] = $property;
        }

        // the default, ordered by the headers
        foreach ($columns as $property) {
            $header = $property->getCode();
            if ($header == 'medidas') {
//                dump($header);
            }
//            assert($property->getType() <> $header, $property->getType() . '<>' . $header);
            if (!$property->getType()) {
                $property = self::parseConfigHeader('att.string', $property->getCode());
            }
//            $property = self::parseConfigHeader('att.string', $column);
            // a map can overwrite a property, usually because the column headers are simply names.  We could combine this above.
            $mapPosition = 0;
            foreach ($map as $regEx => $rule) {
                $columnCode = $property->getCode();
                try {
                    if (preg_match($regEx, $columnCode)) {
                        if (is_null($rule)) {
                            // ignore?
                        }
                        $property = self::parseConfigHeader($rule, $columnCode);
                        $property->setOrderIdx($mapPosition);
//                        dd($mapPosition, $property);
//                        $property->set(Property::SETTING_MAP_POSITION, $mapPosition);
//                        dd($property, $mapPosition, $regEx, $columnCode);
                        break;
                        $outputHeader = (string)$property;
                        // @todo: multiple rules based on pattern, like scurity?
                        $outputSchema[$property->getCode()] = $property->__toString();
                    }
                } catch (\Exception $exception) {
                    assert(false, sprintf("Error matching %s to %s", $regEx, $columnCode));
                    continue;
                }
                $mapPosition++;
            }
            $schema->addProperty($property);
        }
//        assert(count($csvSchema) == count($columns));
        return $schema;

        return $outputSchema;
        if (0)
        {
            {
                if (0)
                {

//                    $columnType = $rule; // for now
//                    if (!str_contains($rule, '?')) {
//                        $rule .= '?';
//                    }
//                    [$dottedConfig, $settingsString] = explode('?', $rule);
//                    $settings = Parser::parseQueryString($settingsString);
//                    $values = explode('.', $dottedConfig);
//                    $type = array_shift($values);
//                    $internalCode = array_shift($values);
//                    if ($type == '') {
//                        $type = 'string';
//                    }
//                    $outputHeader = $settings['header']??$column;
//                    $outputHeader .= $fieldNameDelimiter . $dottedConfig;
                    if ($columnType) {
//                        $outputHeader .= ':' . $columnType;
                    }
                    unset($settings['header']);
                    if (count($settings)) {
//                        $columnType = json_encode($settings);
//                        $outputHeader .= ':' . $columnType;
//                        $outputHeader .= '?' . http_build_query($settings);
//                        dd($outputHeader);
                    }
//                    $propertyType = TextType::class;
//                    // consider https://symfony.com/doc/current/components/expression_language.html#extending-the-expressionlanguage
//                    if (preg_match('/(.*?)(\(.*?\))/', $type, $m)) {
//                        $type = $m[1];
//                        $params = $m[2];
//                    } else {
//                        $params = null;
//                    }
                    $propertyType = match($type) {
                        'db' => match($internalCode) {
                            'code' => TextType::class,
                            'label' => TextareaType::class,
                            'description' => TextareaType::class,
                            default => assert(false, $internalCode . '/' . $type)

                        },
                        'rel'  =>  CollectionType::class,
                        'array|',
                        'array' => TextType::class, // join values with delimiter in formatter for output
                        'string' => TextType::class,
                        'cat' => TextType::class, // really a relationship to the cat table -- choice?
                        'bool' => CheckboxType::class,
                        'int' => NumberType::class,
                        'float' => NumberType::class,
                        'att',
                        'attr' => match ($internalCode) {
                            'int' => NumberType::class,
                            'string' => TextType::class,
                            'text' => TextareaType::class,
                            'url' => UrlType::class,
                            'date' => DateType::class,
                            default => assert(false, $internalCode)
                        },
                        default => assert(false, $type)
                    };
//                    if ($type == 'array|') {
//                        dd($type, $propertyType);
//                    }

                    $options = [];

                    $settings['propertyType'] = $type;
                    $settings['internalCode'] = $internalCode;
                    // ack! Terrible names.
                    $settings['formType'] = $propertyType;
                    $settings['type'] = $type;
                    $settings['propertyType'] = $type; // for liForm
                    $settings['internalCode'] = $internalCode;

                    if (count($settings)) {
                        $options['attr'] = $settings;
//                        $columnType = json_encode($settings);
//                        $outputHeader .= ':' . $columnType;
//                        dd($settings, $column, $type);
//                        $outputHeader .= '?' . http_build_query($settings);
//                        dd($outputHeader);
                    }

                    if ($settings['label']??false) {
                        $options['label'] = $settings['label'];
                        unset($settings['label']);
                    }
                    if (count($settings)) {
                        $options['attr'] = $settings;
//                        dd($settings, $outputHeader);
//                        $outputHeader .= '?' . http_build_query($settings);
                    }

                    if ($propertyType == CollectionType::class) {
                        $options['allow_add'] = true;
                    }
                    $outputSchema[$column] = array_merge([
                        'column' => $column,
                        'type' => $dottedConfig,
                    ],
                        $settings);

                    $columnType = $outputHeader;
//                    dd($type, $rule, $settings, $values, $columnType, $outputHeader);
                    assert($type);
//                    dump($columnType, $rule);
//                    break;
                } else {
                }
            }

//            dd($property, $dottedConfig, $columnType, $settings);

            $csvSchema[$column] = $columnType;
//            if (!array_key_exists($column, $outputSchema)) {
//                $columnType = 'att.string';
//                $settings = [];
//                $outputSchema[$column] = array_merge([
//                    'dottedConfig' => $dottedConfig,
//                    'column' => $column,
//                    'type' => $columnType,
//                ], $settings);
//            }

        }
        return [
            'schema' => $csvSchema,
            'outputSchema' => $outputSchema
            ];
    }


    /**
     * @param array $columns
     *
     * @return array
     */
    public function parseRow(array $columns)
    {
        assert(!empty($this->schema));
        if (empty($this->schema)) {
            $this->schema = self::createConfigFromMap(headers: $columns);
        }
        $schema = $this->schema;

        if ($schema->getPropertyCount() !== count($columns)) {
//            dd($schema, $columns, array_diff(array_keys($schema), array_keys($columns)));
        }
        if ($schema->getPropertyCount() !== count($columns)) {
            dd(message: sprintf("Schema has %d, columns has %d", $schema->getPropertyCount(), count($columns)), schema: $schema, columns: $columns,
                xdiff: array_diff(array_keys($columns), $schema->getSortedPropertyCodes()),
                diff: array_diff($schema->getSortedPropertyCodes(), array_keys($columns)));
        }
//        assert(count($schema) == count($columns), sprintf("mismatch %d %d",
//            count($schema), count($columns)));

        $zipper = collect($columns)->zip($schema->getPropertyCodes());
        $valueRules = $this->config['valueRules']??[];

//        dd($zipper, $valueRules, $schema);
        $flat = $zipper->flatMap(function ($pair, $index) use ($valueRules, $schema) {
            list($value, $type) = $pair;
            foreach ($valueRules as $valueRule => $newValue) {
                if ($value == $valueRule) {
                    $value = $newValue;
                }
            }

            $key = $schema->getPropertyCodes()[$index];
            if ($type == 'cat') dd($key, $type, $value);

            $parsed = $this->getValue($type, $value, $key);
            return [$key => $parsed];
        });
        $all = $flat->all();
        return $all;
    }

    /**
     * @param Reader $reader
     *
     * @return array
     */
    protected function parse(Reader $reader): \Generator
    {
        $reader->setDelimiter($this->getConfigValue('delimiter', $this->defaultDelimiter));
//        $reader->setEnclosure($this->getConfigValue('enclosure', $this->defaultEnclosure));
//        $reader->setEscape($this->getConfigValue('escape', $this->defaultEscape));
        $reader->setHeaderOffset(0);
        // All conversion methods are removed in favor of conversion classes, use League\Csv\CharsetConverter
//        $reader->setInputEncoding($this->getConfigValue('encoding', $this->defaultEncoding));

//        $rows =  new Collection($reader);
        foreach ($reader->getIterator() as $idx=>$row) {
//            dd($row, $this, $this->defaultDelimiter);
//        foreach ($rows as $idx => $row) {
            if ($idx == 0 && ($this->getConfigValue('skipTitle', $this->skipTitle))) {
                continue;
            }
//            dump($this->config['schema'], json_encode($this->config['schema']));
            $parsedRow = $this->parseRow($row);
//            dd($parsedRow);
            yield $parsedRow;
        }

    }

    /**
     * @param string $key
     * @param string $default
     *
     * @return mixed
     */
    protected function getConfigValue($key, $default)
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    // parse header:dotted.config to [header, dotted.config]
    // header to [header, null]
    static public function parseDottedConfig(string $dottedConfig): array
    {
        if (str_contains($dottedConfig, ':')) {
            [$header, $dottedConfig] = explode(':', $dottedConfig);
        } else {
            $header = $dottedConfig;
            $dottedConfig = null;
        }
//        $property = new Property(code: $header);
//        return $property;
        return [$header, $dottedConfig];

    }
    static public function parseConfigHeader(?string $config, string $originalCode=null): ?Property
    {
        if (empty($config)) {
            return null;
        }
        $indexType = null;
        if (!str_contains($config, '?')) {
            $config .= '?';
        }
        [$dottedConfig, $settingsString] = explode('?', $config);
        if (!str_contains($dottedConfig, ':') && $originalCode) {
            $dottedConfig = $originalCode . ':' . $dottedConfig;
        }
        [$header, $dottedConfig] = self::parseDottedConfig($dottedConfig);

        $settings = self::parseQueryString($settingsString);
        if ($dottedConfig && str_contains($dottedConfig, '.')) {
            [$type, $values] = explode('.', $dottedConfig, 2);
            $parameters = $values; // what's after the .
        } else {
//            $type = null;
            $type = $dottedConfig; // no params, native type, like string, which is really att.string
            $parameters = null;
        }


        $lastChar = $type ? substr($type, -1) : null;
        if (in_array($lastChar, ['#'])) {
//            $header = $header ? rtrim($header, $lastChar) : null;
            $type = $type ? rtrim($type, $lastChar): null; // hack!
//            $subType = $subType ? rtrim($subType, $lastChar): null; // hack!
            $indexType = 'INDEX';
//            $settings['index'] = 'INDEX';
        }
        // only an attribute if the type is att?
//        if (in_array($type, Property::ATTRIBUTE_TYPES )) {
//            $parameters = $type;
//            $type = Property::TYPE_ATTRIBUTE;
//        }

        $subType = $parameters;

        // handle array shortcut
        # this is a comma-separated string of pks
//        - collection_codes:rel.coll[@pk],

        if (str_contains($subType, '@')) {
            $lastChar = $subType ? substr($subType, -1) : null;
            if (in_array($lastChar, ['|', '$', ',', '/'])) {
                $subType = $subType ? rtrim($subType, $lastChar) : null;
                $settings['delim'] = $lastChar;
            }
            if (preg_match('/(.*?)\[(@.*?)]/', $subType, $mm)) {
                $subType = $mm[1];
                $indexType = 'INDEX'; // is this true?
                $settings['valueType'] =  $mm[2];
//                dd($mm, $subType, $mm, $settings);
            }
//            dd($header, $subType, $settings, $indexType);
        }

        // old way, but now we gobble these characters :-(
        $lastChar = $header ? substr($header, -1) : null;
        if (in_array($lastChar, ['|', '$', ',', '/'])) {
//            $parameters = null;
//            dump(dottedConfig: $dottedConfig, config: $config, type: $type);
//            $type = "array$lastChar";
            $header = $header ? rtrim($header, $lastChar) : null;
            $subType = $subType ? rtrim($subType, $lastChar): null; // hack!
//            $subType = $type; // for rel.mat, the subtype is 'mat'
//            dd(type: $type);
            $settings['delim'] = $lastChar;
            $type = Property::PROPERTY_ARRAY;
//            $map["/^$newColumn$/"] = "array($lastChar)";

        }
        if ($type) {
            // long form?
            if (preg_match('/(array)(.)/', $type, $m)) {
                $settings['delim'] = $m[2];
//                $parameters = $m[2];
                $type = $m[1];
            }
        }
        if ($type == Property::TYPE_DATABASE) {
//            $header = $parameters; // db types must be our internal codes
        }


        $property = new Property($header, $type, $subType, $settings, $indexType);
        if ($property->getDelim()) {
//            dd($property, $type, $parameters, subType: $subType, settings: $settings);
        }

        return $property;

    }

    /**
     * @param string $type
     * @param string $value
     *
     * @return mixed
     *
     * @throws UnsupportedTypeException
     */

    protected function getValue($config, $value, $key)
    {
        // format: [fieldname:][dottedConfig]?settings
//        if (count($settings)) {
//            dd($settings);
//        }
        $property = $this->schema->getProperty($config);
//            $property = self::parseConfigHeader($config);
//        [$header, $dottedConfig, $settings] =
//        $values = explode('.', $dottedConfig);
//        $type = array_shift($values);
        if (!$type = $property->getType()) {
            $type = Property::PROPERTY_STRING;
        }
//        dd($header, $config, $value, $key, $settings, $values, $type);
        assert($type);

//        list($type, $parameters) = $this->parseType($type);
//        dd($type, $values, $parameters);

        if ($type == 'att') {
            $methodName = $this->getMethodName($property->getSubType());
        } else {
            $methodName = $this->getMethodName($type);
        }
        if ($type == 'cat') {
//            dd(static::$customTypes, $methodName, $type, $property);
        }
        if (method_exists($this, $methodName)) {
            $method = [$this, $methodName];
        } elseif ($this->hasCustomType($type)) {
            $method = static::$customTypes[$type];
//            dd('custom type: ' . $type, $method);
        } else {
//            assert(false, $property->__toString() . ' ' . $config);
            throw new UnsupportedTypeException($type);
        }
//        dd($config,$value, $key, $type);
//        dd($methodName, $type, $value, $property);
//        dump($method, $value, $property);
        return call_user_func_array($method, [$value, $property]);
        try {
//            dump($method, $value, $parameters, $settings);
        } catch (\Exception $exception) {
            assert(false, $exception->getMessage());
//            dd($method, $value, $parameters, $settings);
            return null;
        }
    }

    /**
     * @param string $type
     *
     * @return array
     */
    protected function parseType($type)
    {
        $parameters = [];


        if (strpos($type, ':') !== false) {
            list($type, $parameters) = explode(':', $type, 2);
        }

        return [$type, $parameters];
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected function getMethodName($type)
    {
        return 'parse'.ucfirst($type);
    }

    public function parseAtt($value, Property $property): void {
        // sigh...
        $method = 'parse' . $property->getSubType();
        dd($value, $property);
    }
    /**
     * @param string $value
     *
     * @return string
     */
    protected function parseString($value)
    {
        return (string) $value;
    }

    /**
     * @param string $value
     *
     * @return int
     *
     * @throws CastException
     */
    protected function parseInt($value)
    {
        $this->guardAgainstNonNumeric($value, 'int');

        return (int) $value;
    }

    protected function parseBool(string|int|bool $value): bool
    {
        if ($value == 'false') {
            return false;
        }
        return (bool) $value;
    }


    /**
     * @param string $value
     *
     * @return float
     *
     * @throws CastException
     */
    protected function parseFloat($value)
    {
        $this->guardAgainstNonNumeric($value, 'float');

        return (float) $value;
    }

    /**
     * @param string $string
     * @param string $delimiter
     *
     * @return array
     */
    protected function parseArray($string, Property $property): array
    {
        dd(property: $property);
        return strlen($string) ? explode($property->getDelim(), trim($string)) : [];
    }

    protected function parseDb($string, string $delimiter=","): string
    {
        return $string;
    }

    /**
     * @param string $value
     * @param string $targetType
     *
     * @throws CastException
     */
    protected function guardAgainstNonNumeric($value, $targetType)
    {
        if (!is_numeric($value) && $value != '') {
            assert(false, "Unable to cast value '$value' to type $targetType.");
            throw new CastException("Unable to cast value '$value' to type $targetType.");
        }
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    protected function hasCustomType($type)
    {
        return isset(static::$customTypes[$type]);
    }
}
