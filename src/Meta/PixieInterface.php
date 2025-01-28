<?php

// so we have someplace to track pixie codes and table names
namespace Survos\PixieBundle\Meta;


interface PixieInterface
{

    // obj.t = {'en': 'label': 'abc'}...
    const TRANSLATED_STRINGS = 't'; // in tables with translatable fields, e.g. obj.t, that holds the translations indexed by locale.fields, obj.en.label='cup'
    const TRANSLATION_LABEL = 'label';
    // this is a pixie table, universal
    const PIXIE_STRING_TABLE = 'str'; // table in kv, contains strings and translations
    const PIXIE_STRING_TRANSLATION_KEY = 'trans'; // key in kv.str that holds the translated indexed by locale


//    const PIXIE_TRANSLATION='translation';
    const string PIXIE_IMAGE_SUFFIX='image'; // media?  this is also the filename suffix


    const IMAGE_TABLE='image';

}

