<?php

namespace Survos\PixieBundle\Command;

use App\Entity\Instance;
use App\Entity\Owner;
use App\EventListener\TranslationEventListener;
use App\Message\TranslationMessage;
use App\Metadata\ITableAndKeys;
use App\Repository\OwnerRepository;
use App\Repository\ProjectRepository;
use App\Service\AppService;
use App\Service\LibreTranslateService;
use App\Service\PdoCacheService;
use App\Service\PennService;
use App\Service\ProjectConfig\PennConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\GridGroupBundle\Service\CsvDatabase;
use Survos\PixieBundle\Event\ImageEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Survos\SaisBundle\Model\AccountSetup;
use Survos\SaisBundle\Model\ProcessPayload;
use Survos\SaisBundle\Service\SaisClientService;
use Survos\Scraper\Service\ScraperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\ConfigureWithAttributes;
use Zenstruck\Console\IO;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;
use function PHPUnit\Framework\containsOnlyNull;

#[AsCommand('pixie:media', 'process the -images database')]
final class PixieMediaCommand extends InvokableServiceCommand
{

    use RunsCommands, RunsProcesses;

    // we are using the digmus translation dir since most projects are there.

    public function __construct(
        private readonly LoggerInterface     $logger,
        private EventDispatcherInterface     $eventDispatcher,
        private PixieService                 $pixieService,
        private SaisClientService            $saisClientService,
        private readonly HttpClientInterface $httpClient, private readonly MessageBusInterface $messageBus,
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
        #[Argument(description: 'config code')] ?string                      $configCode,
        #[Option(description: 'dispatch resize requests')] ?bool             $dispatch = true,
//        #[Option(description: 'populate the image keys with the iKv')] ?bool $merge = false,
//        #[Option(description: 'sync images from sais')] ?bool $sync = false,
//        #[Option(description: 'index when finished')] bool                   $index = false,
        #[Option()] int                                                      $limit = 50,
        #[Option()] int                                                      $batch = 10,
    ): int
    {
        $tableName = 'obj'; // could have images elsewhere.
        $configCode ??= getenv('PIXIE_CODE');
        $config = $this->pixieService->getConfig($configCode);
        $iKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode, mode: ITableAndKeys::PIXIE_IMAGE))->getStorageBox();
        $iKv->select(ITableAndKeys::IMAGE_TABLE);
        $approx = (int)$config->getSource()->approx_image_count;
        if (!$approx) {
            $approx = $iKv->count();
            $this->io()->error("Missing source|approx_image_count in config.  currently " . $iKv->count());
            return self::FAILURE;
        }

        // setup an account on sais with an approx for path creation
        $results = $this->saisClientService->accountSetup(new AccountSetup(
            $configCode,
            $approx,
            mediaCallbackUrl: null
        ));

        $dispatchCache = [];
        if ($dispatch) {
            // dispatch to sais
            $actualCount = $iKv->count(ITableAndKeys::IMAGE_TABLE);
            $count = $limit ? min($limit, $actualCount) : $actualCount;
            $io->title(sprintf("Dispatching $count images %s::%s ",
                $this->saisClientService->getProxyUrl(),
                $this->saisClientService->getApiEndpoint()
            ))
            ;
            $progressBar = new ProgressBar($io, $count);
            $images = [];
            // we should dispatch a request for an API key, and set the callbacks and approx values


            foreach ($iKv->iterate(ITableAndKeys::IMAGE_TABLE, order: ['ROWID' => 'desc']) as $key => $item) {
                $data = $item->getData(true);
                SurvosUtils::assertKeyExists('originalUrl', $data);
                $imageUrl = $data['originalUrl'];
                assert($imageUrl, json_encode($data, JSON_PRETTY_PRINT));
                $images[] = [
                    'url' => $imageUrl,
                    'context' => $data['context']??[],
                    ];
//                $xxh3 = SaisClientService::calculateCode($imageUrl, $configCode);
                $progressBar->advance();
                if (($progressBar->getProgress() === $limit) || ($progressBar->getProgress() % $batch) === 0) {
                    $results = $this->saisClientService->dispatchProcess(new ProcessPayload(
                        $configCode,
                        $images,
                    ));
                    $this->logger->info(count($results) . ' images dispatched');
                    foreach ($results as $result) {
                        $imageCode = $result['code'];
//                        $dispatchCache[$result['code']] = $result['thumbData'];
                        // dispatch an event that the application (like museado) can listen for to keep the data updated without polling
                        $this->messageBus->dispatch(new ImageEvent($configCode, $imageCode, $iKv, $result));
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
