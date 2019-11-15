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
use AE\ConnectBundle\Salesforce\Synchronize\Configuration;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Actions;
use AE\ConnectBundle\Salesforce\Synchronize\Sync;
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
     * @var Sync
     */
    private $processor;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    private $listeners = [];

    public function __construct(Sync $processor, EventDispatcherInterface $dispatcher)
    {
        parent::__construct(null);
        $this->processor = $processor;
        $this->dispatcher        = $dispatcher;
    }

    protected function configure()
    {
        $this->setName('ae_connect:bulk')
             ->addArgument('connection', InputArgument::REQUIRED, 'The connection you want to bulk sync to and from')
             ->addOption(
                 'types',
                 't',
                 InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                 'If provided, only these SObject Types will be synced.',
                 []
             )
            ->addOption(
                'queries',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'If provided, only these queries will be ran to produce results for syncing',
                []
            )
             ->addOption(
                 'update-inbound',
                 'i',
                 InputOption::VALUE_NONE,
                 'Update existing records in the local database when saving inbound records from Salesforce'
             )
            ->addOption(
                'create-inbound',
                null,
                InputOption::VALUE_NONE,
                'Insert new entities into the local database '.
                'from Salesforce if they don\'t exist in the local database'
            )
             ->addOption(
                 'update-outbound',
                 'o',
                 InputOption::VALUE_NONE,
                 'update existing records in the Salesforce when sending data from local database'
             )
            ->addOption(
                'create-outbound',
                null,
                InputOption::VALUE_NONE,
                'creates new records in Salesforce when sending data from local database'
            )
             ->addOption(
                 'sync-sfids',
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
        $types      = $input->getOption('types');

        $output->writeln(
            sprintf(
                '<info>Starting bulk sync for %s</info>',
                empty($types) ? 'all types' : implode(', ', $types).' type(s)'
            )
        );

        $pull = new Actions();
        $pull->sfidSync = $input->getOption('sync-sfids');
        $pull->validate = true;
        $pull->create = $input->getOption('create-inbound');
        $pull->update = $input->getOption('update-inbound');

        $push = new Actions();
        $push->sfidSync = $input->getOption('sync-sfids');
        $push->validate = true;
        $push->create = $input->getOption('create-outbound');
        $push->update = $input->getOption('update-outbound');

        $config = new Configuration(
            $input->getArgument('connection'),
            $input->getOption('types'),
            $input->getOption('queries'),
            $input->getOption('sync-sfids'),
            $pull,
            $push
        );

        $this->outputDetails($input, $output, $config);

        $this->processor->sync($config);

        $output->writeln('Bulk sync is now complete.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param $types
     * @param $updateFlag
     */
    protected function outputDetails(InputInterface $input, OutputInterface $output, Configuration $config): void
    {
        if (!empty($config->getQueries())) {
            $queries = $config->getQueries();
            $output->writeln(
                sprintf(
                    '<info>AE Connect will now sync data using these queries : %s to %s.</info>',
                    implode(', ', $queries),
                     $input->getFirstArgument()
                )
            );
        } else {
            $types = $config->getSObjectTargets();
            $output->writeln(
                sprintf(
                    '<info>AE Connect will now sync %s to %s.</info>',
                    empty($types) ? 'all types' : implode(', ', $types).' type(s)',
                    null === $input->getFirstArgument() ? ' all connections' : $input->getFirstArgument()
                )
            );
        }
        $output->writeln(
            'This operation is not easy to reverse. It is recommended you backup your data before continuing.'
        );

        if ($config->getPullConfiguration()->update) {
            $output->writeln(
                '<comment>Local entities will be updated with data from Salesforce.</comment>'
            );
        }

        if ($config->getPullConfiguration()->create) {
            $output->writeln(
                '<comment>New records will be created in the local database with data from Salesforce.</comment>'
            );
        }

        if ($config->getPushConfiguration()->create) {
            $output->writeln(
                '<comment>New Salesforce objects will be created with data from the local database.</comment>'
            );
        }

        if ($config->getPushConfiguration()->update) {
            $output->writeln(
                '<comment>Existing Salesforce objects will be updated with data from the local database.</comment>'
            );
        }

        if ($config->needsSFIDsCleared()) {
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
