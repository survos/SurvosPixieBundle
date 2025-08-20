<?php

namespace Survos\PixieBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Entity\OriginalImage;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Survos\SaisBundle\Model\AccountSetup;
use Survos\SaisBundle\Model\ProcessPayload;
use Survos\SaisBundle\Service\SaisClientService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('pixie:media', 'dispatch to sais the original images')]
final class PixieMediaCommand extends Command
{


    // we are using the digmus translation dir since most projects are there.

    public function __construct(
        private readonly LoggerInterface     $logger,
        private EventDispatcherInterface     $eventDispatcher,
        private PixieService                 $pixieService,
        private SaisClientService            $saisClientService,
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus, private readonly OriginalImageRepository $originalImageRepository,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        IO                                                                   $io,
        EntityManagerInterface                                               $entityManager,
        LoggerInterface                                                      $logger,
        ParameterBagInterface                                                $bag,
        PropertyAccessorInterface                                            $accessor,
        OriginalImageRepository $originalImageRepository,
        #[Argument(description: 'config code')] ?string                      $configCode,
        #[Option(description: 'dispatch resize requests')] ?bool             $dispatch = true,
        #[Option(description: 'setup account (@todo: auto)')] ?bool             $setup = false,
//        #[Option(description: 'populate the image keys with the iKv')] ?bool $merge = false,
//        #[Option(description: 'sync images from sais')] ?bool $sync = false,
//        #[Option(description: 'index when finished')] bool                   $index = false,
        #[Option()] int                                                      $limit = 50,
        #[Option()] int                                                      $batch = 10,
    ): int
    {
        $tableName = 'obj'; // could have images elsewhere.
        $configCode ??= getenv('PIXIE_CODE');

        $ctx = $this->pixieService->getReference($configCode);
        $config = $ctx->config;

//        $iKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode, mode: ITableAndKeys::PIXIE_IMAGE))->getStorageBox();
//        $iKv->select(ITableAndKeys::IMAGE_TABLE);
        $approx = $config->getSource()->approx_image_count;
        if (!$approx) {
            $approx = $originalImageRepository->count();
            $this->io()->error("Missing source|approx_image_count in config.  currently "
                . $approx);
            return self::FAILURE;
        }

        if ($setup) {
            // setup an account on sais with an approx for path creation
            $results = $this->saisClientService->accountSetup(new AccountSetup(
                $configCode,
                $approx,
                mediaCallbackUrl: null
            ));
        }

        $dispatchCache = [];
        if ($dispatch) {

            // dispatch to sais
            // @todo: dont dispatch if finished.
            $actualCount = $this->originalImageRepository->count();
            $count = $limit ? min($limit, $actualCount) : $actualCount;
            $io->title(sprintf("Dispatching $count images %s::%s ",
                $this->saisClientService->getProxyUrl(),
                $this->saisClientService->getApiEndpoint()
            ))
            ;

            $progressBar = SurvosUtils::createProgressBar($io, $approx);
            $images = [];
            // we should dispatch a request for an API key, and set the callbacks and approx values
            $qb = $this->originalImageRepository->createQueryBuilder('o')
                ->orderBy('o.createdAt', 'DESC');
            /**
             * @var  $idx
             * @var OriginalImage $image
             */
            foreach ($progressBar->iterate($qb->getQuery()->toIterable()) as $idx=>$image) {
//            foreach ($iKv->iterate(ITableAndKeys::IMAGE_TABLE, order: ['ROWID' => 'desc'])
//                     as $key => $item) {
                $images[] = [
                    'url' => $image->getUrl(),
                    'context' => [], // hmm, how important is it at sais?
                    ];
//                $xxh3 = SaisClientService::calculateCode($imageUrl, $configCode);

                if (($progressBar->getProgress() === ($limit-1)) || ($progressBar->getProgress() % $batch) === 0) {
                    $results = $this->saisClientService->dispatchProcess(new ProcessPayload(
                        $configCode,
                        $images,
                    ));
                    $this->logger->info(count($results) . ' images dispatched');
                    foreach ($results as $result) {
                        $imageCode = $result['code'];
                        dd($result, $image);
//                        $dispatchCache[$result['code']] = $result['thumbData'];
                        // dispatch an event that the application (like museado) can listen for to keep the data updated without polling
//                        $this->messageBus->dispatch(new ImageEvent($configCode, $imageCode, $result));
//                        dd($result);
                        $this->logger->info(
                            sprintf("%s %s %s", $result['code'], $result['originalUrl'], $result['root']));
                    }
                    $images = [];
                    if ($limit && ($limit <= $progressBar->getProgress())) {
//                    dd($limit, $batch, $progressBar->getProgress());
                        break;
                    }
                }
            }
            $progressBar->finish();
        }

        //
        $this->io()->writeln("\n\nfinished, now run pixie:merge --images --sync");
        return self::SUCCESS;

    }

    private function mergeImageData(Item $item, StorageBox $iKv): array
    {
//        $images = [
//            [
//                'code' => 'abc',
//                'thumb'=> '...',
//                'orig'=> '...'
//        ];
        $images = [];
        foreach ($item->imageCodes() ?? [] as $key) {
            $imageData = ['code' => $key];
            foreach ($iKv->iterate('resized', where: [
                'imageKey' => $key,
            ], order: [
                'size' => 'asc',
            ]) as $row) {
                $imageData[$row->size()] = $row->url();
//                $imagesBySize[$row->size()][]=
//                    [
////                    'caption' => '??',
//                    'code'=>$key,
//                    'url' => $row->url()
//                ];
            }
            $images[] = $imageData;
        }
//        if (count($images)) {
//            dd($images);
//        }
        return $images;
    }


}
