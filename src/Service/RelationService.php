<?php


namespace Survos\PixieBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\PixieBundle\{Entity\Instance, Repository\RelationRepository};
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Field\RelationField;
use Survos\PixieBundle\Entity\FieldSet;
use Survos\PixieBundle\Entity\InstanceInterface;
use Survos\PixieBundle\Entity\Relation;
use function Symfony\Component\String\u;

class RelationService
{
    public function __construct(
        // circular reference.  Needs refactoring anyway
//        private PixieService $pixieService
    ) {
    }

    private function getRepo(): RelationRepository
    {
        return $this->pixieService->getReference()->repo(Relation::class);
    }

    public function deleteRelations(InstanceInterface $instance)
    {

        // could also go through the relations remove them from management, but I think this is better.
        return $this->getRepo()->createQueryBuilder('e')
            ->andWhere('e.leftInstanceId = :instanceId')
            ->orWhere('e.rightInstanceId = :instanceId')
            ->setParameter('instanceId', $instance->getId())
            ->delete()
            ->getQuery()
            ->execute();
    }

    public function addRelation(Instance $leftInstance,
                                string $rightInstanceCode,
                                RelationField $relationField,
//                                RelationField $RelationField,
                                Instance $rightInstance): Relation
    {
        static $seen = []; // this is the relation queue, not the instance queue

        $code = Relation::createCode($leftInstance->getCode(), $rightInstanceCode, $relationField->getCode());
        if (! array_key_exists($code, $seen)) {
            if (! $relation = $this->getRepo()->findOneBy([
                'code' => $code,
            ])) {
                $relation = new Relation($leftInstance, $rightInstanceCode, $relationField, $rightInstance, $code);
                $leftInstance->getCore()->addRelation($relation);
                $leftInstance->addRelation($relation);
                $leftInstance->addRelation($relation);
                $relationField->incRelationCount();
                assert($leftInstance->getProject()->getRelations()->count(), "relation not loaded.");
                if ($rightInstance) {
                    //                    $rightInstance->incRelatedToCount();
                }
                assert($rc = $leftInstance->getRelationCount(), sprintf("relation count not right %d <> %d", $rc, $leftInstance->getRelations()->count()));
            }
            $seen[$code] = $relation;
        }
        return $seen[$code];
    }

    public function addInstanceRelations(InstanceInterface $instance)
    {
        // get the references and relationships, which are NOT managed by Doctrine
        $relations = $this->getRepo()->getRelations($instance);
        /** @var Relation $relation */
        foreach ($relations as $relation) {
            /** @var InstanceInterface $rightInstance */
            dd($relation);
//            if ($rightInstance = $this->pixieEntityManager->find($relation->getRelationField()->getRightCore()->getEntityClass(), $relation->getRightInstanceId())) {
//                $relation->setRightInstance($rightInstance);
//                $instance->addRelation($relation);
//            }
        }
    }

    public function getInstanceRelations(InstanceInterface $instance, ?RelationField $RelationField = null)
    {
        // get the references and relationships, which are not managed by Doctrine
        $relations = $this->relationRepository->getRelations($instance);
        return $relations;

        //        return array_filter($this->relationRepository->getRelations($instance),
        //            fn(Relation $relation) => $relation->getLeftInstanceId() === $instance->getId());
    }

    public function getOrCreateRelationField(
        Core     $projectCore,
        Core     $relatedProjectCore,
        string   $code,
        ?string   $reverse = null,
        bool     $renderLabelAsRelation = false,
        bool     $throwErrorIfMissing = false,
        bool     $createReverse = true,
        ?string   $label = null,
        ?string   $reverseLabel = null,
        ?FieldSet $fieldSet=null,
        ?FieldSet $reverseFieldSet=null
    ): RelationField {
        static $seen = [];
        if (! count($seen)) {
            foreach ($projectCore->getRelationFields() as $relationField) {
                $seen[$relationField->__toString()] = $relationField;
            }
        }
//        foreach ($projectCore->getFields() as $field) {
//            dump($field->getCode() . '/' . $field->getShortClass());
//        }
//        if ($projectCore->isObj()) dd($seen);
//        dd($projectCore->getFields(), $projectCore->getRelationFields());


        $key = RelationField::key3($projectCore, $code, $relatedProjectCore);
        //        dd($seen, $key);
        if (! array_key_exists($key, $seen)) {
            {
                assert(!$throwErrorIfMissing, "Missing $code in \n" . join("\n", array_keys($seen)));
                assert($reverse, "reverse is needed for new rt");
                if (!$label) {
                    $label = u($code)->replace('_',' ')->title()->toString();
                }
                if (!$reverseLabel) {
                    $reverseLabel = u($code)->replace('_',' ')->title()->toString();
                }

//                dd($key, $seen, $code);

                $relationField = (new RelationField(
                    code: $code,
                    reverseCode: $reverse,
                    leftCore: $projectCore,
                    rightCore: $relatedProjectCore
                ))->setLabel($label);
                $this->pixieEntityManager->persist($relationField);
                if ($fieldSet) {
                    $fieldSet->addField($relationField);
                }

                if ($createReverse) {
                    $reverseRelationField = (new RelationField(
                        code: $reverse,
                        reverseCode: $code,
                        leftCore: $relatedProjectCore,
                        rightCore: $projectCore
                    ))
                        ->setIsReverse(true)
                        ->setLabel($reverseLabel);
                    $reverseFieldSet->addField($reverseRelationField);
//                    $this->appService?->validate($reverseFieldSet);

                }
            }
//            $relationField->setCurrentLocale($projectCore->getProjectLocale()); // hack, not persisted or needed
//            $relationField->getImportFields()

            $seen[$relationField->__toString()] = $relationField;
//            dd($seen, $key, $relationField->getId(), $relationField->getCode());
        }
//        $seen[$key]
//            ->setRenderLabelsAsRelation($renderLabelAsRelation)
//            ->setReverseLabel($reverse);
        return $seen[$key];
    }
}
