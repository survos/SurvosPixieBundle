<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Event\CsvHeaderEvent;
use Survos\PixieBundle\Event\ImportFileEvent;
use Survos\PixieBundle\Event\RowEvent;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\StorageBox;
use Survos\WorkflowBundle\Service\WorkflowHelperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Yaml\Yaml;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('pixie:iterate', 'Iterative over a pixie database, sending events"')]
final class IterateCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;
    public function __construct(
        private LoggerInterface          $logger,
        private ParameterBagInterface    $bag,
        private EventDispatcherInterface $eventDispatcher,
        private MessageBusInterface $bus,
        private readonly PixieService $pixieService,
        private ?WorkflowHelperService   $workflowHelperService=null,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        IO                                                    $io,
        PixieService                                          $pixieService,
        PixieImportService                                    $pixieImportService,
        #[Argument(description: 'config code')] string        $pixieCode,
        #[Argument(description: 'table name')] string         $tableName,
        #[Option(description: 'workflow transition')] ?string $transition=null,
        // marking CAN be null, which is why we should set it when inserting
        #[Option(description: 'workflow marking')] ?string    $marking=null,
        #[Option(description: 'message transport')] ?string   $transport=null,
        #[Option] int                                         $limit = 100,

    ): int
    {
        // do we need the Config?  Or is it all in the StorageBox?
        $kv = $pixieService->getStorageBox($pixieCode);
        $config = $pixieService->getConfig($pixieCode);
        assert($kv->tableExists($tableName), "Missing table $tableName: \n".join("\n", $kv->getTables()));

        $table = $config->getTables()[$tableName];
        $workflow = null;
        if ($workflowName = $table->getWorkflow()) {
            $workflow = $this->workflowHelperService->getWorkflowByCode($table->getWorkflow());

            /* eh, nice idea, maybe someday
            dd($workflow, $workflowName);
            if ($transition) {
                if (!constant($workflow::class . "::$transition")) {
                    $io->error("Invalid transition $transition in workflow $workflowName " . $workflow::class);
                }
            }
            */
        }
        {
            $io->title($tableName);
            $kv->select($tableName);
            $where = [];
            if ($marking) {
                $where = ['marking' => $marking];
            }
            $progressBar = new ProgressBar($io, $count = $kv->count(where: $where));
            $idx = 0;
            foreach ($kv->iterate(where: $where) as $key => $item) {
                $idx++;
                // since we have the workflow and transition, we can do a "can" here.
                if ($workflow) {
                    if (!$workflow->can($item, $transition)) {
//                        dump("$item cannot transition to $transition");
                        continue;
                    } else {
                        $envelope = $this->bus->dispatch($message = new PixieTransitionMessage(
                            $pixieCode,
                            $key,
                            $tableName,
                            $transition,
                            $workflowName,
                            $transport
                        ));
                    }
                    // if there's a workflow and a transition, dispatch a transition message, otherwise a simple row event
                } else {
                    // no workflow, so dispatch the row event and let the listeners handle it.
                    $this->eventDispatcher->dispatch(
                        $rowEvent = new RowEvent(
                        // this looks more like an item!!
                            $pixieCode,
                            $tableName,
                            (array)$item->getData(),
                            $item,
                            $key,
                            $idx,
                            $count,
                            type: RowEvent::LOAD,
                            action: self::class,
                            context: [
                                'transition' => $transition,
                                'transport' => $transport
                            ])
                    );

                }

                // if it's an event that changes the values, like a cleanup, we need to update the row.
                // if it's just dispatching an event, then we don't.
                // @todo: update
                if ($limit && $idx >= $limit) {
                    break;
                }
                $progressBar->advance();
            }
            $progressBar->finish();
        }

        $io->success('Pixie:iterate success ' . $kv->getFilename());
        return self::SUCCESS;
    }

}
