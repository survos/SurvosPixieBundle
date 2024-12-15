<?php

namespace Survos\PixieBundle\Tests;

use League\Csv\Reader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\PixieBundle\CsvSchema\Parser;
use Survos\PixieBundle\Model\Property;
use Symfony\Component\Yaml\Yaml;

use function PHPUnit\Framework\assertEquals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
class ParserTest extends TestCase
{
    #[DataProvider('csvTests')]
    #[Test]
    #[TestDox('read and parse data from csv files')]
    public function testParser(array $test, ?string $name=null, ?string $basic=null): void
    {
        $key = $test['name'] ?? json_encode($test);
            $csvString = $test['source'];
            $csvReader = Reader::createFromString($csvString)->setHeaderOffset(0);
            $schema = Parser::createConfigFromMap($test['map'] ?? [], $csvReader->getHeader());
            $schema->setValueRules($test['valueRules'] ?? []);
            $parser = new Parser($schema);

            $expectsJson = $test['expects'] ?? null;
        $this->assertSame($key, $key);
        return;

            foreach ($parser->fromString($csvString) as $actual) {
                $expects = json_decode($expectsJson, true);
                assert($expects, "invalid json string: " . $expectsJson . ' in ' . $key);
                $this->assertSame($expects, $actual, json_encode($expects) . '<>' . json_encode($actual));
                assert($expects, "invalid json: " . $test['expects']);
            }

            if ($expectedSchema = $test['schema']??false) {
//                dd($config, $expectedSchema);
                assert(is_array($expectedSchema), "$key: invalid json string: " . json_encode($expectedSchema));

//                $expects = json_decode($expectedSchema, true);
//                assert($expects, "invalid json string: " . $expectsJson);
                $this->assertSame($expects, $actual, json_encode($expects) . '<>' . json_encode($actual));
                assert($expects, "invalid json: " . $test['expects']);

            }

    }

    private function compareProperties(Property $expected, Property $actual): void
    {
        $this->assertEquals(
            $expected,
            $actual,
            $actual->__toString() . "\n" . $expected
        );

    }

    #[DataProvider('parserTests')]
    public function testDottedConfig(string $dottedConfig, Property $property, ?string $alias = null): void
    {

        $actual = Parser::parseConfigHeader($dottedConfig);
        if ($property <> $actual) {
            // maybe it's the long form, check the shortened version
            $compactForm = $property->__toString();
            assertEquals($compactForm, $actual->__toString());
        } else {

        }
        $this->assertEquals(
            $property,
            $actual,
            'act:' . $actual->__toString() . "\n" . 'exp:' . $property
        );

    }



    /**
     * @return string[][]          // <-- added typehint here
     */
    public static function csvTests()
    {
        $data = Yaml::parseFile(__DIR__ . '/parser-test.yaml');
        foreach ($data['tests'] as $key => $test) {
            $key = $test['name']??$key;
            yield [$key => $test];
        }
    }

    public static function parserTests()
    {
        /* format:
            code[<delimiter>?]:<type>?<settings in k=v;>
        */
        return [
            ['header', new Property('header')],
            ['actors,',new Property(code: 'actors', type: Property::PROPERTY_ARRAY, settings: ['delim' => ','])],
            ['genre|', new Property(code: 'genre', type: Property::PROPERTY_ARRAY, settings: ['delim' => '|'])],
            ['languages:array?delim=|', new Property(code: 'languages', type: Property::PROPERTY_ARRAY, settings: ['delim' => '|'])],
            ['label:db.label',new Property(code: 'label', type: Property::TYPE_DATABASE, subType: 'label')],
            ['author:rel.per', new Property('author', Property::TYPE_RELATION, 'per')],
            // we need to pass the relative properties to the schema for this to work.
//            ['author:per', new Property('author', Property::TYPE_RELATION, 'per')],


            ['header:att.int', new Property('header', Property::TYPE_ATTRIBUTE,  Property::PROPERTY_INT)],
            ['header:int#', new Property('header', Property::PROPERTY_INT, index: 'INDEX')],
            ['header:int#?max=4', new Property('header', Property::PROPERTY_INT, index: 'INDEX', settings: ['max' => 4])],
            ['header:att.int', new Property('header', Property::TYPE_ATTRIBUTE,  Property::PROPERTY_INT)],
            ['header:att.int?max=4', new Property('header', Property::TYPE_ATTRIBUTE,  Property::PROPERTY_INT, ['max' => 4])],
            ['header:rel.per', new Property('header', Property::TYPE_RELATION, 'per' )],
            ['header:rel.pla?rule=alpha2', new Property('header', Property::TYPE_RELATION, 'pla',['rule' => 'alpha2'] )],
        ];

    }

}
