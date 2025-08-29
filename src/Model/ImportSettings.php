<?php

namespace Survos\PixieBundle\Model;

class ImportSettings
{
    public function __construct(
        public bool $autocreateFromCodes = true, // eg. @bob creates bob
        public bool $autocreateFromLabel = true, // "Bob Jones" creates @bob_jones and label "Bob Jones"
        public bool $purgeConfig = false, // remove config and instances
        public bool $purgeInstances = false, // remove just instances
        public bool $loadInstances = true,
        public bool $deleteProject = true, // remove just instances
        public ?string $profileFilename = '',
        public ?string $dataFilename = '',
    ) {
    }
}
