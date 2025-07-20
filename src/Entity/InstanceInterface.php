<?php


namespace Survos\PixieBundle\Entity;

use App\Entity\IdInterface;
use App\Entity\ImportDataInterface;
use App\Entity\TranslatableFieldsProxyInterface;

interface InstanceInterface extends IdInterface, TranslatableFieldsProxyInterface, ImportDataInterface
{
    public function getShortClass(): string;

    public function getClass(): string;

//    public function getImages(): array;
//
//    public function setImages(array $images): self;
//
    //    public function setRelations(ArrayCollection $relations): self;
    //    public function getRelations(): ArrayCollection;
    //    public function addRelation(Relation $relation): self;

    public function getReferenceCount(): ?int;

    public function setReferenceCount(?int $referenceCount): self;

    public function incReferenceCount(): void;

    public function getRelationCount(): ?int;

    public function setRelationCount(?int $relationCount): self;

    public function incRelationCount(): void;

    public function getRelatedToCount(): ?int;

    public function setRelatedToCount(?int $relatedToCount): self;

    public function incRelatedToCount(): void;

    public function getCode(): ?string;

    public function setCode(string $code): self;

    public function getIRP(?array $addlParams = []): array;

    //    public function setTypeConfig(?Category $type): self;
    //    public function getTypeConfig(): ?Category;
}
