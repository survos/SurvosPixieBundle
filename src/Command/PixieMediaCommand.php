<?php

namespace Survos\PixieBundle\Command;

use App\Entity\Instance;
use App\Entity\Owner;
use App\EventListener\TranslationEventListener;
use App\Message\TranslationMessage;
use App\Repository\OwnerRepository;
use App\Repository\ProjectRepository;
use App\Service\AppService;
use App\Service\LibreTranslateService;
use App\Service\PdoCacheService;
use App\Service\PennService;
use App\Service\ProjectConfig\PennConfigService;
use App\Service\ProjectService;
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
        private readonly LoggerInterface                      $logger,
        private EventDispatcherInterface $eventDispatcher,
        private PixieService $pixieService,
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
        #[Option(description: 'populate the image keys with the iKv')]
        ?bool    $merge = false,
        #[Option(description: 'index when finished')] bool $index=false,
        ?ProjectService                                                                                 $ps=null,
    ): int
    {
        $tableName = 'obj'; // could have images elsewhere.
        $configCode ??= getenv('PIXIE_CODE');
        $config = $this->pixieService->getConfig($configCode);
        $iKv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode, mode: PixieInterface::PIXIE_IMAGE))->getStorageBox();
        $kv = $this->eventDispatcher->dispatch(new StorageBoxEvent($configCode))->getStorageBox();

        $kv->beginTransaction();
        if ($merge) {
            foreach ($kv->iterate($tableName) as $item) {
                $imageCodes = $item->imageCodes();
//                if ($item->imageCount()) dd($item->imageCount(), $imageCodes, $item);
                if ($imageCodes) {
                    // from iKv
                    $images = $this->mergeImageData($item, $iKv);
                    $data = array_merge($item->getData(true), [
                        'images' => $images,
                    ]);
                    $kv->set($data, $tableName);
//                    ($item->getKey() == 44) && dump($imageCodes, $images);
                }
            }
        }
        $kv->commit();
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
