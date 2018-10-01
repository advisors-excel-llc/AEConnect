<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 5:35 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;
use Doctrine\Common\Collections\ArrayCollection;

class Client implements ClientInterface
{
    /** @var BayeuxClient */
    private $streamingClient;

    /** @var ArrayCollection|ChannelSubscriberInterface[] */
    private $channelSubscribers;

    public function __construct(BayeuxClient $client)
    {
        $this->streamingClient    = $client;
        $this->channelSubscribers = new ArrayCollection();
    }

    public function addSubscriber(ChannelSubscriberInterface $subscriber)
    {
        if (!$this->channelSubscribers->contains($subscriber)) {
            $name  = $subscriber->getChannelName();
            $parts = explode('?', $name);
            $this->channelSubscribers->set($parts[0], $subscriber);
        }
    }

    public function subscribe(string $channelName, ConsumerInterface $consumer)
    {
        if (!$this->channelSubscribers->containsKey($channelName)) {
            $this->channelSubscribers->get($channelName)->addSubscriber($consumer);
        }
    }

    public function start()
    {
        foreach ($this->channelSubscribers as $topic) {
            $channel = $this->streamingClient->getChannel($topic->getChannelName());

            if (null !== $channel) {
                foreach ($topic->getSubscribers() as $subscriber) {
                    $channel->subscribe($subscriber);
                }
            }
        }

        $this->streamingClient->start();
    }

    public function stop()
    {
        if (!$this->streamingClient->isDisconnected()) {
            $this->streamingClient->disconnect();
        }
    }

    public function getClient(): BayeuxClient
    {
        return $this->streamingClient;
    }
}
