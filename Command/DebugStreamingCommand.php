<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/7/19
 * Time: 10:02 AM
 */

namespace AE\ConnectBundle\Command;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Inbound\DebugConsumer;
use AE\ConnectBundle\Streaming\ChangeEvent;
use AE\ConnectBundle\Streaming\Client;
use AE\ConnectBundle\Streaming\GenericEvent;
use AE\ConnectBundle\Streaming\PlatformEvent;
use AE\ConnectBundle\Streaming\Topic;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\SalesforceRestSdk\Bayeux\Extension\ReplayExtension;
use AE\SalesforceRestSdk\Bayeux\Extension\SfdcExtension;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class DebugStreamingCommand extends Command implements LoggerAwareInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;
    use LoggerAwareTrait;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SfdcExtension
     */
    private $sfdcExtension;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        SfdcExtension $sfdcExtension,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct('debug:ae_connect:stream')
              ->addOption('replay-id', 'r', InputOption::VALUE_OPTIONAL)
        ;

        $this->connectionManager = $connectionManager;
        $this->sfdcExtension     = $sfdcExtension;
        $this->logger            = $logger ?: new NullLogger();
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->addArgument('connection', InputArgument::OPTIONAL, 'The name of the connection to debug', 'default');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionName = $input->getArgument('connection');
        $connection     = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            $output->writeln(sprintf('<error>No connection named "%s" was found</error>', $connectionName));

            return;
        }

        $replayExtension = $this->container->get("ae_connect.connection.$connectionName.replay_extension");
        $replayId        = $input->getOption('replay-id');
        $replayExt       = new ReplayExtension($replayId ?: $replayExtension->getReplayId());
        $client          = $connection->getStreamingClient();
        /** @var BayeuxClient $bayeuxClient */
        $bayeuxClient = new BayeuxClient(
            $client->getClient()->getTransport(),
            $client->getClient()->getAuthProvider(),
            $this->logger
        );

        $bayeuxClient->addExtension($this->sfdcExtension);
        $bayeuxClient->addExtension($replayExt);

        $debugClient = new Client($bayeuxClient);

        foreach ($client->getChannelSubscribers() as $subscriber) {
            switch (get_class($subscriber)) {
                case Topic::class:
                    $channelSubscriber = new Topic();
                    $channelSubscriber->setName($subscriber->getName());
                    $channelSubscriber->setFilters($subscriber->getFilters());
                    $debugClient->addSubscriber($channelSubscriber);
                    break;
                case ChangeEvent::class:
                    $channelSubscriber = new ChangeEvent($subscriber->getName());
                    $debugClient->addSubscriber($channelSubscriber);
                    break;
                case PlatformEvent::class:
                    $channelSubscriber = new PlatformEvent($subscriber->getName());
                    $debugClient->addSubscriber($channelSubscriber);
                    break;
                case GenericEvent::class:
                    $channelSubscriber = new GenericEvent($subscriber->getName());
                    $debugClient->addSubscriber($channelSubscriber);
                    break;
            }
        }

        $debugClient->subscribe(new DebugConsumer($output, $bayeuxClient->getTransport()->getSerializer()));
        $debugClient->start();
    }
}
