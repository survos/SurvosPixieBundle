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
use Survos\GridGroupBundle\Service\CsvDatabase;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Event\StorageBoxEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
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

#[AsCommand('pixie:media', 'process the -images database')]
final class PixieMediaCommand extends InvokableServiceCommand
{

    use RunsCommands, RunsProcesses;

    // we are using the digmus translation dir since most projects are there.

    public function __construct(
        private readonly LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        private PixieService             $pixieService,
        private SaisClientService        $saisClientService,
        private readonly HttpClientInterface $httpClient,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        IO                                                                                             $io,
        EntityManagerInterface                                                                         $entityManager,
        LoggerInterface                                                                                $logger,
        ParameterBagInterface                                                                          $bag,
        PropertyAccessorInterface                                                                      $accessor,
        #[Argument(description: 'config code')] ?string                                                $configCode,
        #[Option(description: 'dispatch resize requests')] ?bool    $dispatch = false,
        #[Option(description: 'populate the image keys with the iKv')] ?bool    $merge = false,
        #[Option(description: 'index when finished')] bool $index=false,
        #[Option()] int $limit=50,
        #[Option()] int $batch=5,
    ): int
    {
        $tableName = 'obj'; // could have images elsewhere.
        $configCode ??= getenv('PIXIE_CODE');
        $config = $this->pixieService->getConfig($configCode);
        $iKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode, mode: ITableAndKeys::PIXIE_IMAGE))->getStorageBox();
        $cache=[];

        if ($dispatch) {
            // dispatch to sais
            $count = $iKv->count(ITableAndKeys::IMAGE_TABLE);
            $progressBar = new ProgressBar($io, $count);
            $images = [];
            foreach ($iKv->iterate(ITableAndKeys::IMAGE_TABLE) as $key=>$item) {
                $data = $item->getData();
                $images[] = $item->imageUrl();
                $progressBar->advance();
                if (($progressBar->getProgress() === $limit) || ($progressBar->getProgress() % $batch) === 0) {
                    $results = $this->saisClientService->dispatchProcess(new ProcessPayload(
                        $configCode,
                        $images,
                        ['tiny','small','large']
                    ));
                    $this->logger->warning(count($results).' images processed');
                    foreach ($results as $result) {
                        $cache[$result['code']] = $result['thumbData'];
                        if (empty($result['thumbData']['tiny'])) {
//                        dd($data, $key, $item);
                        }
//                    dump($result['thumbData'], $result['originalUrl']);
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

        $kv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode))->getStorageBox();
//        dump(array_keys($cache));

        if ($merge) {
            $count = $kv->count($tableName);
            $kv->beginTransaction();
            $progressBar = new ProgressBar($io, $count);
            foreach ($kv->iterate($tableName) as $item) {
                $thumbData = [];
                foreach ($item->images() as $imageData) {
                    $progressBar->advance();
                    $code = $imageData->code;
                    if (in_array($code, $cache)) {
                        $thumbData[] = $cache[$code];
                    } else {
                        if ($mediaData = $this->saisClientService->fetch('/api/media/' . $code)) {
                            $thumbData[] = $mediaData['thumbData']??[];
                        }
                        continue;
                        dd($thumbData, $imageData, $code);
                        $db = $iKv->get($code, ITableAndKeys::IMAGE_TABLE);
                        if (!$db) {
                            $this->logger->error("Missing $code in image cache and iKv");
//                        dd($item->images(), $iKv->get($code, ITableAndKeys::IMAGE_TABLE));
                        }
                    }
                }
                $data = $item->getData(true);
                $data[ITableAndKeys::SAIS] = $thumbData;

//                $images = $this->mergeImageData($item, $iKv);
//                $data = array_merge($item->getData(true), [
//                    'images' => $images,
//                ]);

//                dd(after: $data, images: $thumbData);

                $kv->set($data, $tableName);

                $imageCodes = $item->imageCodes();
//                if ($item->imageCount()) dd($item->imageCount(), $imageCodes, $item);
                if ($imageCodes) {
                    dd($imageCodes);
                    // from iKv
                    $images = $this->mergeImageData($item, $iKv);
                    $data = array_merge($item->getData(true), [
                        'images' => $images,
                    ]);
                    $kv->set($data, $tableName);
//                    ($item->getKey() == 44) && dump($imageCodes, $images);
                }
                if ( ($progressBar->getProgress() % $batch) === 0) {
                    $kv->commit(); // @todo: batch
                    $kv->beginTransaction();
                }
            }
            $kv->commit();
        }

        $this->io()->writeln("\n\nfinished");
        if ($index) {
            $batchSize = 500;
                $cli = "pixie:index $configCode --table $tableName --reset --batch=$batchSize";
                $this->io()->warning('bin/console ' . $cli);
                $this->runCommand($cli);
        }
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
        foreach ($item->imageCodes()??[] as $key) {
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
