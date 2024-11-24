<?php

declare(strict_types=1);

namespace Survos\PixieBundle\DataCollector;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Debug\TraceableStorageBox;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Antoine Makdessi <amakdessi@me.com>
 */
final class PixieDataCollector extends AbstractDataCollector
{

    public function __construct(
        private PixieService $pixieService,
    )
    {
    }
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $data = $this->pixieService->getData();

        $this->data[$this->getName()] = !empty($data) ? $this->cloneVar($data) : null;
//        dd($this->data);
    }

    public function getName(): string
    {
        return self::class;
    }


    /** @internal used in the DataCollector view template */
    public function getCollectedData(): mixed
    {
        $data = $this->data[$this->getName()] ?? null;
        return $data;
    }
}
