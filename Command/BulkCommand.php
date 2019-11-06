<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 7:07 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Salesforce\Bulk\BulkDataProcessor;
use AE\ConnectBundle\Salesforce\Bulk\Events\Events;
use AE\ConnectBundle\Salesforce\Bulk\Events\ProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\UpdateProgressEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class BulkCommand extends Command
{
    /**
     * @var BulkDataProcessor
     */
    private $bulkDataProcessor;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    private $listeners = [];

    public function __construct(BulkDataProcessor $processor, EventDispatcherInterface $dispatcher)
    {
        parent::__construct(null);
        $this->bulkDataProcessor = $processor;
        $this->dispatcher        = $dispatcher;
    }

    protected function configure()
    {
        $this->setName('ae_connect:bulk')
             ->addArgument('connection', InputArgument::OPTIONAL, 'The connection you want to bulk sync to and from')
             ->addOption(
                 'types',
                 't',
                 InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                 'If provided, only these SObject Types will be synced.',
                 []
             )
             ->addOption(
                 'update-inbound',
                 'i',
                 InputOption::VALUE_NONE,
                 'Update existing records in the local database when saving inbound records from Salesforce'
             )
             ->addOption(
                 'update-outbound',
                 'o',
                 InputOption::VALUE_NONE,
                 'Update existing records in the Salesforce when sending data from local database'
             )
             ->addOption(
                 'insert-new',
                 null,
                 InputOption::VALUE_NONE,
                 'Insert new entities into the local database '.
                 'from Salesforce if they don\'t exist in the local database'
             )
             ->addOption(
                 'clear-sfids',
                 'c',
                 InputOption::VALUE_NONE,
                 'Clear the preexisting Salesforce Ids in the local database. Helpful if connecting to a new '.
                 'Salesforce Org or Sandbox'
             )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $updateFlag = $this->buildUpdateFlag($input);
        $types      = $input->getOption('types');
        $this->outputDetails($input, $output, $types, $updateFlag);

        $output->writeln(
            sprintf(
                '<info>Starting bulk sync for %s</info>',
                empty($types) ? 'all types' : implode(', ', $types).' type(s)'
            )
        );

        $this->wireupProgressListeners($output);

        $this->bulkDataProcessor->process(
            $input->getArgument('connection'),
            $types,
            $updateFlag
        );

        $this->unwireProgressListeners();

        $output->writeln('Bulk sync is now complete.');
    }

    /**
     * @param InputInterface $input
     *
     * @return int
     */
    protected function buildUpdateFlag(InputInterface $input): int
    {
        $updateFlag = 0;

        if ($input->getOption('update-inbound')) {
            $updateFlag |= BulkDataProcessor::UPDATE_INCOMING;
        }

        if ($input->getOption('update-outbound')) {
            $updateFlag |= BulkDataProcessor::UPDATE_OUTGOING;
        }

        if ($input->getOption('insert-new')) {
            $updateFlag |= BulkDataProcessor::INSERT_NEW;
        }

        if ($input->getOption('clear-sfids')) {
            $updateFlag |= BulkDataProcessor::UPDATE_SFIDS;
        }

        return $updateFlag;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $types
     * @param $updateFlag
     */
    protected function outputDetails(InputInterface $input, OutputInterface $output, $types, $updateFlag): void
    {
        $output->writeln(
            sprintf(
                '<info>AE Connect will now sync %s to %s.</info>',
                empty($types) ? 'all types' : implode(', ', $types).' type(s)',
                null === $input->getFirstArgument() ? ' all connections' : $input->getFirstArgument()
            )
        );
        $output->writeln(
            'This operation is not easy to reverse. It is recommended you backup your data before continuing.'
        );

        if ($updateFlag & BulkDataProcessor::UPDATE_INCOMING) {
            $output->writeln(
                '<comment>Local entities will be updated with data from Salesforce.</comment>'
            );
        }

        if ($updateFlag & BulkDataProcessor::UPDATE_OUTGOING) {
            $output->writeln(
                '<comment>Salesforce will be updated with data from the local database.</comment>'
            );
        }

        if ($updateFlag & BulkDataProcessor::INSERT_NEW) {
            $output->writeln(
                '<comment>New records will be created in the local database with data from Salesforce.</comment>'
            );
        }

        if ($updateFlag & BulkDataProcessor::UPDATE_SFIDS) {
            $output->writeln(
                '<comment>Clear all Salesforce IDs from the database. '
                .'External Ids will be used to match existing records.</comment>'
            );
        }
    }

    /**
     * @param OutputInterface $output
     */
    protected function wireupProgressListeners(OutputInterface $output): void
    {
        $operation = 'Importing';
        $progress  = new ProgressBar($output);

        $this->listeners[Events::SET_TOTALS] = function (ProgressEvent $event) use ($progress, $output, $operation) {
            $total = $event->getOverallTotal();
            $output->writeln("<info>$operation $total records</info>");
            $progress->start($event->getOverallTotal());
        };

        $this->listeners[Events::UPDATE_PROGRESS] = function (UpdateProgressEvent $event) use ($progress, $operation) {
            $type      = $event->getKey();
            $processed = $event->getProgressFor($type);
            $total     = $event->getTotal($type);
            $progress->setMessage("$operation $type records ($processed / $total)");
            $progress->setProgress($event->getOverallProgress());
        };

        $this->listeners[Events::COMPLETE] = function (ProgressEvent $event) use (&$operation, $progress) {
            $progress->finish();

            if ($operation === 'Importing') {
                $operation = 'Exporting';
            }
        };

        foreach ($this->listeners as $event => $listener) {
            $this->dispatcher->addListener($event, $listener);
        }
    }

    protected function unwireProgressListeners(): void
    {
        foreach ($this->listeners as $event => $listener) {
            $this->dispatcher->removeListener($event, $listener);
        }
    }
}
