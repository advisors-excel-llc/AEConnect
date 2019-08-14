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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Enqueue\Symfony\Consumption\ConfigurableConsumeCommand as BaseConsumeCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ConsumeCommand extends BaseConsumeCommand
{
    protected static $defaultName = 'ae_connect:consume';

    /**
     * @var DriverInterface
     */
    private $driver;

    /**
     * @var OutboundQueue
     */
    private $outboundQueue;

    public function __construct(
        ContainerInterface $container,
        DriverInterface $driver,
        OutboundQueue $queue
    ) {
        parent::__construct(
            $container,
            'ae_connect',
            'enqueue.transport.ae_connect.queue_consumer',
            'enqueue.transport.ae_connect.processor_registry'
        );
        $this->driver        = $driver;
        $this->outboundQueue = $queue;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->configureLimitsExtensions();
        $this->configureQueueConsumerOptions();
        $this->configureLoggerExtension();

        $this
            ->setDescription(
                'A worker that consumes message from a broker. '.
                'To use this broker you have to explicitly set a queue to consume from '.
                'and a message processor service'
            )
            ->addArgument('processor', InputArgument::OPTIONAL, 'Provided for posterity')
            ->addArgument(
                'queues',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Provided for posterity.',
                ['default']
            )
            ->addOption('transport', 't', InputOption::VALUE_OPTIONAL, 'Provided for posterity.')
            ->addOption(
                'wait',
                'w',
                InputOption::VALUE_OPTIONAL,
                'The maximum time in seconds the consumer should wait for more messages before sending the request to Salesforce',
                10
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        if ('default' === $input->getOption('logger')) {
            $input->setOption('logger', 'stdout');
        }

        $input->setArgument('processor', OutboundProcessor::class);
        $input->setArgument('queues', [$this->driver->createQueue('default')]);
        $input->setOption('transport', 'ae_connect');

        return parent::execute($input, $output);
    }

    protected function getLimitsExtensions(InputInterface $input, OutputInterface $output)
    {
        $extensions = parent::getLimitsExtensions($input, $output);

        array_unshift(
            $extensions,
            new SalesforceOutboundExtension($this->outboundQueue, $input->getOption('wait').' seconds')
        );

        return $extensions;
    }
}
