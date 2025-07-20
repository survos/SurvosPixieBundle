<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:make', "Execute the build steps to create csv/json", aliases: ['pixie:build'])]
final class PixieMakeCommand extends InvokableServiceCommand
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
        IO                                                          $io,
        PixieService                                                $pixieService,
        PixieImportService                                          $pixieImportService,
        #[Argument(description: 'config code')] ?string              $configCode,
        #[Option(description: 'build from source')] ?bool           $build,
        #[Option(description: 'make from /json')] ?bool           $make,
        #[Option(description: 'dry run, just show commands')] ?bool $dry,

    ): int
    {
        $this->initialized = true;
        $dry ??= false; // if null, false
        $configCode ??= getenv('PIXIE_CODE');
        $config = $pixieService->selectConfig($configCode);
        $source = $config->getSource();
        $build ??= true;
        if ($build) {
            $this->process($source->build, $dry);
        }
        if ($make) {
            $this->process($source->make, $dry);
        }

        $io->success($this->getName() . " " .  $configCode);
        return self::SUCCESS;
    }

    private function process(array $steps, bool $dry)
    {
    foreach ($steps as $step) {
        switch ($step['action']) {
            case 'fetch':
                $this->io()->writeln("fetching {$step['source']} to {$step['target']}");
                $dry || $this->fetch($step['source'], $step['target'], $dry);
                break;
            case 'bash':
                $command = $step['cmd'];
                $this->io()->writeln("running $command...");
                $dry || $this->runProcess($command);

            case 'unzip':
                $zip = new \ZipArchive;
                if ($res = $zip->open($step['source'])) {
                    $zip->extractTo($step['target']); // , array('pear_item.gif', 'testfromfile.php'));
                    $zip->close();
                } else {
                    $this->io()->error("Cannot open " . $step['source']);
                }
                $this->io()->success(sprintf("%s extracted to %s", $step['source'], $step['target']));
                break;

            case 'cmd':
                $command = $step['cmd'];
//                $command = str_replace('$code$', $configCode, $command);
                $this->io()->writeln("running $command...");
                $dry || $this->runCommand($command);
                break;
            default:
                assert(false, $step['action']);
//)
        }
    }
}

    private function fetch(string $url, string $destination, ?bool $dry): void
    {
        if (!file_exists($destination)) {
            mkdir($destination, 0777, true);
        }
        if (str_ends_with($destination, '/')) {
            $destination .= pathinfo($url, PATHINFO_BASENAME);
        }
        if (!file_exists($destination)) {
            $this->io()->writeln("Fetching $url...");
            $response = $this->httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode == 200) {
                // $statusCode = 200
                $contentType = $response->getHeaders()['content-type'][0];
                // $contentType = 'application/json'
                $content = $response->getContent();

                file_put_contents($destination, $content);
                $this->io()->writeln("$destination written.");
            } else {
                dd($statusCode, $url);
            }
        } else {
            $this->io()->writeln("$destination already exists");

        }


    }




}
