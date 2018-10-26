<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/23/18
 * Time: 10:34 AM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Salesforce\Inbound\Polling\PollingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PollCommand extends Command
{
    /**
     * @var PollingService
     */
    private $polling;

    public function __construct(PollingService $polling)
    {
        parent::__construct(null);
        $this->polling = $polling;
    }

    protected function configure()
    {
        $this->setName('ae_connect:poll')
             ->setDescription('Poll for changes on objects that are not supported by the Streaming API')
             ->addArgument(
                 'connectionName',
                 InputArgument::OPTIONAL,
                 'The name of the configured connection to poll',
                 'default'
             )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionName = $input->getArgument('connectionName');

        $output->writeln('<info>Polling for objects on '.$connectionName.'</info>');
        $this->polling->poll($connectionName);
        $output->writeln('<info>Polling Complete</info>');
    }
}
