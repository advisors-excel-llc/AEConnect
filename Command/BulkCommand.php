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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $updateFlag = ($input->hasArgument('update-inbound') & BulkDataProcessor::UPDATE_INCOMING) |
            ($input->hasArgument('update-outbound') & BulkDataProcessor::UPDATE_OUTGOING);
        $types = $input->getOption('types');

        $output->writeln(
            sprintf(
                '<info>Starting bulk sync for %s</info>',
                empty($types) ? 'all types' : implode(', ', $types) . ' types'
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
