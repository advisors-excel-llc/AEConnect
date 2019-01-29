<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:08 PM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Salesforce\Outbound\Enqueue\Extension\SalesforceOutboundExtension;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use Enqueue\Client\DriverInterface;
use Enqueue\Consumption\ChainExtension;
use Enqueue\Consumption\Extension\LoggerExtension;
use Enqueue\Consumption\Extension\SignalExtension;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Symfony\Consumption\QueueConsumerOptionsCommandTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class ConsumeCommand extends Command
{
    use QueueConsumerOptionsCommandTrait;

    /**
     * @var QueueConsumer
     */
    private $consumer;

    /**
     * @var DriverInterface
     */
    private $driver;

    /** @var OutboundProcessor */
    private $processor;

    /**
     * @var OutboundQueue
     */
    private $outboundQueue;

    public function __construct(
        QueueConsumer $consumer,
        DriverInterface $driver,
        OutboundProcessor $processor,
        OutboundQueue $queue
    ) {
        parent::__construct(null);
        $this->consumer      = $consumer;
        $this->driver        = $driver;
        $this->processor     = $processor;
        $this->outboundQueue = $queue;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->configureQueueConsumerOptions();

        $this->setName('ae_connect:consume')
             ->addOption(
                 'wait',
                 'w',
                 InputOption::VALUE_OPTIONAL,
                 'The maximum time in seconds the consumer should wait for more messages before sending the request to Salesforce',
                 10
             )
        ;
    }


    /**
     * @inheritDoc
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setQueueConsumerOptions($this->consumer, $input);

        $queue          = $this->driver->createQueue('default');
        $chainExtension = new ChainExtension(
            [
                new SalesforceOutboundExtension($this->outboundQueue, $input->getOption('wait').' seconds'),
                new LoggerExtension(new ConsoleLogger($output)),
                new SignalExtension(),
            ]
        );

        $this->consumer->bind($queue, $this->processor);
        $this->consumer->consume($chainExtension);
    }
}
