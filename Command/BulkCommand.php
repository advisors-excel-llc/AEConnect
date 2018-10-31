<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 7:07 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Salesforce\Bulk\BulkDataProcessor;
use Symfony\Component\Console\Command\Command;
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

    public function __construct(BulkDataProcessor $processor)
    {
        parent::__construct(null);
        $this->bulkDataProcessor = $processor;
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
                 InputOption::VALUE_OPTIONAL | InputOption::VALUE_NONE,
                 'Update existing records in the local database when saving inbound records from Salesforce'
             )
             ->addOption(
                 'update-outbound',
                 'o',
                 InputOption::VALUE_OPTIONAL | InputOption::VALUE_NONE,
                 'Update existing records in the Salesforce when sending data from local database'
             )
             ->addOption(
                 'batch-limit',
                 'l',
                 InputOption::VALUE_OPTIONAL,
                 'The maximum number of records to send to Salesforce in a single batch.'
                 .' This is really meant to prevent stress on application servers that could cause crashes.',
                 2000
             )
             ->addOption(
                 'clear-sfids',
                 'c',
                 InputOption::VALUE_OPTIONAL | InputOption::VALUE_NONE,
                 'Clear the preexisting Salesforce Ids in the local database. Helpful if connecting to a new '.
                 'Salesforce Org or Sandbox'
             )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $updateFlag = ($input->hasOption('update-inbound') & BulkDataProcessor::UPDATE_INCOMING) |
            ($input->hasOption('update-outbound') & BulkDataProcessor::UPDATE_OUTGOING) |
            ($input->hasOption('clear-sfids') & BulkDataProcessor::UPDATE_SFIDS);
        $types      = $input->getOption('types');

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
        } else {
            $output->writeln(
                '<comment>Only new entities will be created with data from Salesforce.</comment>'
            );
        }

        if ($updateFlag & BulkDataProcessor::UPDATE_OUTGOING) {
            $output->writeln(
                '<comment>Salesforce will be updated with data from the local database.</comment>'
            );
        } else {
            $output->writeln(
                '<comment>Only new records will be created with in Salesforce.</comment>'
            );
        }

        if ($updateFlag & BulkDataProcessor::UPDATE_SFIDS) {
            $output->writeln(
                '<comment>Clear all Salesforce IDs from the database. '
                .'External Ids will be used to match existing records.</comment>'
            );
        }

        /** @var QuestionHelper $helper */
        $helper           = $this->getHelper('question');
        $consumerQuestion = new ConfirmationQuestion(
            'Have you stopped all ae_connect:consume and ae_connect:listen processes? (y/n) '
        );

        if (!$helper->ask($input, $output, $consumerQuestion)) {
            $output->writeln(
                '<error>Please stop all ae_connect:consume and ae_connect:listen processes before continuing.</error>'
            );

            return;
        }

        $confirmQuestion = new ConfirmationQuestion(
            'Is your data backed up and are the settings correct to continue? (y/n) '
        );

        if (!$helper->ask($input, $output, $confirmQuestion)) {
            return;
        }

        $output->writeln(
            sprintf(
                '<info>Starting bulk sync for %s</info>',
                empty($types) ? 'all types' : implode(', ', $types).' type(s)'
            )
        );

        $this->bulkDataProcessor->process(
            $input->getFirstArgument(),
            $types,
            $updateFlag,
            $input->getOption('batch-limit')
        );

        $output->writeln('Bulk sync is now complete.');
    }
}
