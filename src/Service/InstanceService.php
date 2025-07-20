<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use App\Entity\ListItem;
use Survos\PixieBundle\Entity\Instance;
use Survos\PixieBundle\Entity\Project;

class InstanceService
{
    public function __construct(
        readonly private Project $project
    ) {
    }

    public function createInstance($objData): Instance
    {
        // get the projectCore from the coreCode
        $instance = new Instance();
        $instance
//            ->setTranslatableLocale($this->project->getProjectLocale())
            ->setProject($this->project);
        //        $this->project->addInstanceReference()

        foreach ($objData['categories'] ?? [] as $cat => $catValue) {
        }
        return $instance;
    }
}
