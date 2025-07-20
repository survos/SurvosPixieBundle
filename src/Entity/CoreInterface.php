<?php

namespace Survos\PixieBundle\Entity;

interface CoreInterface
{

    public const PERSON = 'per';
    public const DATA_RIGHTS = 'c_data';
    public const IMAGE_RIGHTS = 'c_images';

    public const COLLECTION = 'coll';

    public const ORG = 'org';
    public const LOAN = 'loan';

    public const OBJECT = 'obj';

    public const LOT = 'lot';
    public const EXPOSITION = 'expo';

//    public const CORE_COMMON = [self::OBJECT, self::PERSON, self::STORAGE];

    public const LIST = 'lst';

    public const SET = 'set';

    public const ITEM = 'set_item'; // in a set, not a list

    public const STORAGE = 'loc';

    public const PLACE = 'pla';
    public const CULTURE = 'cul';
    public const MATERIAL = 'mat';
    public const MEDIUM = 'med';
    public const TECHNIQUE = 'tec';
    public const KEYWORDS = 'key';

    public const EVENT = 'evt';
    public const TIMELINE = 'time';
    public const TAG = 'tag';

    public const RELATIONSHIP_TYPE = 'rt';

    // attribute types
    public const MEAS = 'meas';
    public const MASS = 'mass';
    public const SCALE = 'scale';
    public const CLASSIFICATION = 'cla';
    public const COORD = 'coord';
}
