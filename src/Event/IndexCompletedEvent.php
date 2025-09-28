<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Event;

use Survos\PixieBundle\Entity\Owner;
use Survos\PixieBundle\Service\IndexNamingService;
use Symfony\Contracts\EventDispatcher\Event;

class IndexCompletedEvent extends Event
{
    public function __construct(
        private Owner $owner,
        private string $locale,
        private int $documentCount,
        private ?string $taskId = null,
        private ?string $batchId = null,
        private array $additionalData = []
    ) {}

    public function getOwner(): Owner
    {
        return $this->owner;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDocumentCount(): int
    {
        return $this->documentCount;
    }

    public function getTaskId(): ?string
    {
        return $this->taskId;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function getPixieCode(): string
    {
        return $this->owner->getPixieCode();
    }

    public function getIndexName(): string
    {
        return IndexNamingService::createIndexName(
            $this->owner->getPixieCode(), 
            $this->locale
        );
    }

    public function getAdditionalData(): array
    {
        return $this->additionalData;
    }

    public function setAdditionalData(array $data): void
    {
        $this->additionalData = $data;
    }
}
