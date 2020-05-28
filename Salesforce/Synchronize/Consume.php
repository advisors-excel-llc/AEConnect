<?php

namespace AE\ConnectBundle\Salesforce\Synchronize;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Actions;
use AE\ConnectBundle\Salesforce\Synchronize\Step\Step;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Message;

class Consume
{
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /** @var ConnectionManagerInterface */
    private $connectionManager;

    /**
     * @var Step
     */
    private $step;

    public function __construct(EventDispatcherInterface $dispatcher, ConnectionManagerInterface $connectionManager)
    {
        $this->dispatcher = $dispatcher;
        $this->connectionManager = $connectionManager;
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        $data = $message->getData();
        $event = $data->getEvent();

        $pull = new Actions();
        $pull->sfidSync = false;
        $pull->validate = true;
        $pull->create = $event->getType() === "created";
        $pull->update = $event->getType() === "updated";
        $pull->delete = $event->getType() === "deleted";

        $push = new Actions();
        $push->sfidSync = false;
        $push->validate = false;
        $push->create = false;
        $push->update = false;

        foreach ($this->connectionManager->getConnections() as $connection) {
            $configuration = new Configuration(
                $connection->getName(),
                [],
                [],
                false,
                $pull,
                $push,
                null
            );

            $configuration->addsObject($data->getPayload());

        }

        return;
    }
}
