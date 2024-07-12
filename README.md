# Pixie Bundle

A Symfony bundle that leverages Sqlite to create an indexed no-sql datastore.

Inspired by https://gist.github.com/sbrl/c3bfbbbb3d1419332e9ece1bac8bb71c and https://dexie.org/ and the Symfony PDO Cache component.

At its core, the idea is to store strings or unstructured data accessible by a key or a filter.

Initially, it was just a string lookup, which could be a JSON string, for example looking up a wikidata object by its QID, or a movie from a csv file by its imdb_id.

You can customize the import process by adding a .conf file with directives that facilitate renaming fields and munging data.  You can also listen for events during the import and export process.

Pixie can (will, eventually) use json schema files to defining the fields. https://json-schema.org/ using LiFormBundle

The indexes can be defined via the command line, a listener, or a .conf file.  There's a succinct format that is a comma-separated string, and a detailed format with is a hash with the index details as keys.

There is (will be) an API endpoint if api-platform is installed.

Integration with survos/translation-bundle

To import a collection of csv files in a directory to a Pixie database, we'll need to do the following

* each csv file becomes a table, which we often rename
* rename the columns 
* map data to specific columns, by default simply extract the json
* allow arrays and objects to be property encoded, e.g. genre| or tags,
* add columns that aren't present in the csv, e.g. status or createdAt
* tweak the data, including casting to type and applying regex rules
* @todo: map related tables, e.g. artwork.artist -> artist table
* create indexes for filter queries
* meili index the pixie file

## Pixie-specific naming

When creating the table, we add a json snippet after the property creation to indicate some attributes.
To support related tables and translation, there are some specific columns names we use that are (maybe?) prefixed by _ or __:

Or should we do this?

* _key: the primary key of a table that can be used by a related table
* _attributes: the JSON blob of everything that's not a property
* _label: the label that is used when a relation is displayed in another table.  Translatable. Definable
* _description: eventually we'll support defining translatable properties but for now we just support _description




## Setup

All pixie db files have an associated configuration file that describes the mapping and underlying data structure.  Generally it shares the same base filename.

```bash
curl 
bin/console pixie:init movies --dir=./data/imdb 
# ./pixie/movies.yaml created with 4 tables, configure it and run bin/console pixie:import movies --limit 10  
cat > pixie.movies << 'END'
(full config)
END
bin/console pixie:import movies --limit 10  
symfony open:local --path="/pixie/movies"
```

## Loading a Pixie

As the data directories (where the csv/json files are located) can be anywhere, there are a few way to indicate them.

```bash
# explicitly 
bin/console pixie:import /path/to/csv/directory -c directory.yaml
# use source.dir key, absolute, or relative to bundle data_root).  If !exists, use the bundle data_root and append 'code'.
bin/console pixie:import -c code.yaml

```
There are 3 way to load data into pixie.   

## "Special" Tables

Since pixie databases offer a convenient way to work with Excel data, there is a special table for handling drawings (embedded images).
It is created with bin/console grid:excel-to-csv (in the grid-group bundle? In museado?)

Excel stores embedded images as "Drawings".  

Translations are also stored as pixie tables, and have their own section.

## Examples

* Movies (imdb)
* Schools 
* 

### CSV Datasets

* https://www.stats.govt.nz/large-datasets/csv-files-for-download/
* 
### Reading an Existing Pixie

```php
$pixie = new Pixie::Reader('school.pixie');
$pixie->select('mo')
foreach ($pixie->)
```

```bash
wget https://dummyjson.com/products products.json
```
```php
// inject the service

$id = 'tt123';
$kv = $pixieService->getStorageBox('dummy.pixie', [
    'products' => 'sku,brand,category' // first key is text primary key by default
]);

$kv->select('products'); // so that we don't have to pass it each time.

$kv->set($data); // because they key is in the data.
assert($kv->get($id));
assert($kv->has($id));
assert(json_decode($kv->get($id)) == $data);
//
```

The conf file simplifies some of the php calls, but isn't 100% necessary



Suppose we want to filter by category.  First, we need to add an index, dexie-style, to the table.

```php
$kv = $pixieService->getStorageBox('app.db', [
    'movies' => 'imdb_id, year|integer, category|string' // first key is text primary key by default
]);

$rows = $kv->where("year < 2000 and category='drama'")->iterate();
// without the index
$rows = $kv->where("json_extract(value, '$.year') < 2000")->iterate();
```

Often CSV files and other data sources have key names that aren't really compatible with sqlite column names.  To map the old names to the new ones, you can create a sequence of regex rules for the column names.

From MOMA Artists.json

```json
{
  "ConstituentID": 1,
  "DisplayName": "Robert Arneson",
  "ArtistBio": "American, 1930–1992",
  "Nationality": "American",
  "Gender": "male",
  "BeginDate": 1930,
  "EndDate": 1992,
  "Wiki QID": null
}
```

```php
$kv->map([
   '/ConstituentID/' => 'id',
   '/BeginDate/' => 'birthYear',
   '/EndDate/' => 'deathYear'
], [
    'artists'
]);
```

All fields will be converted to camel_case, even without regex rules.

Note that the JSON is NOT compressed, so the keys are duplicated.  So the sqlite file is larger than the CSV by (rowCount * headerRowSize).  
## Usage

```bash
composer require survos/pixie-bundle
```

Initialize StorageBox with the sqlite database name plus any new tables to be created.

Existing tables can be used, but automatic table creation is disabled. 

```php
$kvDb = new StorageBox('translation.db', ['es', 'en'], 'en');
$key = md5('dog');
$kvDb->set($key, 'perro', 'es');
$kvDb->set($key, 'dog'); // defaults to en
$trans = $kvDb->get($key); // dog 
$trans = $kvDb->get($key, 'es'); // perro 
// trans is perro

// cache-like with callcack
$trans = $kvDb->get($key, callback: fn($item) => $this->translator->trans('dog'));

// keys are automatically slugified
$trans = $kvDb->get('My name is', fn($item) => $this->translator->trans($item->key));

// keys are automatically slugified
$trans = $kvDb->get('My name is',   ) => $this->translator->trans($item->key));

```


## Multi-lingual StorageBox

Specifically for kv lookups of text fields that may have translations

```php
$kvDb = new MLSB('property.db', ['label','description']);

$key = 'Q31';
$kvDb->set($key, 'label', 'Belgium', 'en-gb');
$kvDb->set($key, 'description', 'constitutional monarchy in Western Europe', 'en-gb');

$transArray = $kvDb->getFieldsByLocale($key, 'en-gb'); 
// ['label' => 'Belgium'...]

$label = $kvDb->getField($key, 'label', 'en-gb'); 
// 'Belgium'

$transArray = $kvDb->getFields($key); 
// ['en-gb' => ['label' => 'Belgium', 'description'  => ...]]

// ditto for set, needs transactions



## Credits

Debug icon from https://www.svgrepo.com/svg/11690/database
https://www.stats.govt.nz/assets/Uploads/Research-and-development-survey/Research-and-development-survey-2021/Download-data/Research-and-development-survey-2021-CSV-notes.csv
