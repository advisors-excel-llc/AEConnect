<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 5:35 PM.
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use Doctrine\Common\Collections\ArrayCollection;

class Client implements ClientInterface
{
    /** @var BayeuxClient */
    private $streamingClient;

    /** @var ArrayCollection|ChannelSubscriberInterface[] */
    private $channelSubscribers;

    public function __construct(BayeuxClient $client)
    {
        $this->streamingClient = $client;
        $this->channelSubscribers = new ArrayCollection();
    }

    public function getChannelSubscribers()
    {
        return $this->channelSubscribers;
    }

    public function addSubscriber(ChannelSubscriberInterface $subscriber)
    {
        if (!$this->channelSubscribers->contains($subscriber)) {
            $name = $subscriber->getChannelName();
            $parts = explode('?', $name);
            $this->channelSubscribers->set($parts[0], $subscriber);
        }
    }

    public function getSubscriber(string $channelName): ?ChannelSubscriberInterface
    {
        if ($this->channelSubscribers->containsKey($channelName)) {
            return $this->channelSubscribers->get($channelName);
        }

        return null;
    }

    public function subscribe(SalesforceConsumerInterface $consumer)
    {
        $channels = $consumer->channels();

        foreach ($channels as $group => $channelGroup) {
            if (!is_array($channelGroup)) {
                $channelGroup = [$channelGroup];
            }

            switch ($group) {
                case 'topics':
                    $this->subscribeChannelToTopics($channelGroup, $consumer);
                    break;
                case 'objects':
                    $this->subscribeChannelToObjects($channelGroup, $consumer);
                    break;
                case 'platform_events':
                    $this->subscribeChannelToPlatformEvents($channelGroup, $consumer);
                    break;
                case 'generic_events':
                    $this->subscribeChannelToGenericEvents($channelGroup, $consumer);
                    break;
            }
        }
    }

    public function start()
    {
        foreach ($this->channelSubscribers as $topic) {
            $channel = $this->streamingClient->getChannel($topic->getChannelName());

            if (null !== $channel) {
                foreach ($topic->getConsumers() as $subscriber) {
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
        } else {
            $this->streamingClient->terminate();
        }
    }

    public function getClient(): BayeuxClient
    {
        return $this->streamingClient;
    }

    /**
     * @param $channelGroup
     */
    private function subscribeChannelToTopics($channelGroup, SalesforceConsumerInterface $consumer): void
    {
        $channelNames = [];

        foreach ($channelGroup as $name) {
            if ('*' === $name) {
                $keys = $this->channelSubscribers->filter(
                    function (ChannelSubscriberInterface $subscriber) {
                        return '/topic/' === substr($subscriber->getChannelName(), 0, 7);
                    }
                )->getKeys()
                ;

                foreach ($keys as $key) {
                    $parts = explode('?', $key);
                    $channelNames[] = $parts[0];
                }
                break;
            }
            $channelNames[] = '/topic/'.$name;
        }

        foreach ($channelNames as $name) {
            if ($this->channelSubscribers->containsKey($name)) {
                /** @var Topic $channelSubscriber */
                $channelSubscriber = $this->channelSubscribers->get($name);
                $channelSubscriber->addConsumer($consumer);
            }
        }
    }

    private function subscribeChannelToObjects($channelGroup, SalesforceConsumerInterface $consumer): void
    {
        $channelNames = [];

        foreach ($channelGroup as $name) {
            if ('*' === $name) {
                $keys = $this->channelSubscribers->filter(
                    function (ChannelSubscriberInterface $subscriber) {
                        return '/data/' === substr($subscriber->getChannelName(), 0, 6);
                    }
                )->getKeys()
                ;

                $channelNames = array_merge($channelNames, $keys);
                break;
            }
            $channelNames[] = '/data/'.preg_replace('/__(c|C)$/', '__', $name).'ChangeEvent';
        }

        foreach ($channelNames as $name) {
            if ($this->channelSubscribers->containsKey($name)) {
                /** @var ChangeEvent $channelSubscriber */
                $channelSubscriber = $this->channelSubscribers->get($name);
                $channelSubscriber->addConsumer($consumer);
            }
        }
    }

    private function subscribeChannelToPlatformEvents($channelGroup, SalesforceConsumerInterface $consumer): void
    {
        $channelNames = [];

        foreach ($channelGroup as $name) {
            if ('*' === $name) {
                $keys = $this->channelSubscribers->filter(
                    function (ChannelSubscriberInterface $subscriber) {
                        return '/event/' === substr($subscriber->getChannelName(), 0, 7);
                    }
                )->getKeys()
                ;

                $channelNames = array_merge($channelNames, $keys);
                break;
            }
            if (false != preg_match('/__(e|E)$/', $name)) {
                $channelNames[] = '/event/'.$name;
            }
        }

        foreach ($channelNames as $name) {
            if ($this->channelSubscribers->containsKey($name)) {
                /** @var PlatformEvent $channelSubscriber */
                $channelSubscriber = $this->channelSubscribers->get($name);
                $channelSubscriber->addConsumer($consumer);
            }
        }
    }

    private function subscribeChannelToGenericEvents($channelGroup, SalesforceConsumerInterface $consumer): void
    {
        $channelNames = [];

        foreach ($channelGroup as $name) {
            if ('*' === $name) {
                $keys = $this->channelSubscribers->filter(
                    function (ChannelSubscriberInterface $subscriber) {
                        return '/u/' === substr($subscriber->getChannelName(), 0, 3);
                    }
                )->getKeys()
                ;

                $channelNames = array_merge($channelNames, $keys);
                break;
            }

            $channelNames[] = '/u/'.$name;
        }

        foreach ($channelNames as $name) {
            if ($this->channelSubscribers->containsKey($name)) {
                /** @var GenericEvent $channelSubscriber */
                $channelSubscriber = $this->channelSubscribers->get($name);
                $channelSubscriber->addConsumer($consumer);
            }
        }
    }
}
