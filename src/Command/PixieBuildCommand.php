<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\StorageBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:build', "Execute the build steps to create csv/json")]
final class PixieBuildCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        private LoggerInterface       $logger,
        private ParameterBagInterface $bag,
        private readonly PixieService $pixieService,
        private SerializerInterface $serializer,
        private HttpClientInterface $httpClient,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                                                          $io,
        PixieService                                          $pixieService,
        PixieImportService                                    $pixieImportService,
        #[Argument(description: 'config code')] string        $pixieCode,

    ): int
    {
        $this->initialized = true;
        $config = $pixieService->getConfig($pixieCode);

        foreach ($config->getSource()->build as $key => $step) {
            switch ($step['action']) {
                case 'fetch':
                    $url = $step['source'];
                    $io->writeln("Fetching $url...");
                    // download to the right location...
//                    $this->httpClient->get()
                    break;
                case 'cmd':
                    $command = $step['cmd'];
                    $io->writeln("running $command...");
                    $command = str_replace('$code$', $pixieCode, $command);
                    $this->runCommand($command);
                    break;
                default:
                    assert(false, $step['action']);
//)
            }
        }


        $io->success($this->getName() . ' success ' . $pixieCode);
        return self::SUCCESS;
    }




}
