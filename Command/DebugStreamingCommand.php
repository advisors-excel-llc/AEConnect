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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugStreamingCommand extends Command
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    public function __construct(ConnectionManagerInterface $connectionManager)
    {
        parent::__construct('debug:ae_connect:stream');
        $this->connectionManager = $connectionManager;
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

        $client = $connection->getStreamingClient();
        /** @var BayeuxClient $bayeuxClient */
        $bayeuxClient = $client->getClient();
        $debugClient  = new Client($bayeuxClient);

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
