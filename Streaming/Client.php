<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 5:35 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Bayeux\BayeuxClient;
use AE\ConnectBundle\Bayeux\ConsumerInterface;
use Doctrine\Common\Collections\ArrayCollection;

class Client implements ClientInterface
{
    /** @var BayeuxClient */
    private $streamingClient;

    /** @var ArrayCollection|TopicInterface[] */
    private $topics;

    public function __construct(BayeuxClient $client)
    {
        $this->streamingClient = $client;
        $this->topics = new ArrayCollection();
    }

    public function addTopic(TopicInterface $topic)
    {
        if (!$this->topics->contains($topic)) {
            $this->topics->set($topic->getName(), $topic);
        }
    }

    public function subscribe(string $topicName, ConsumerInterface $consumer)
    {
        if (!$this->topics->containsKey($topicName)) {
            $this->topics->get($topicName)->addSubscriber($consumer);
        }
    }

    public function start()
    {
        foreach ($this->topics as $topic) {
            $channel = $this->streamingClient->getChannel($this->getTopicName($topic));

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

    protected function getTopicName(TopicInterface $topic): string
    {
        $name = '/topic/'.$topic->getName();

        $filters = $topic->getFilters();

        if (!empty($filters)) {
            array_walk($filters, function (&$value, $key) {
                $value = "$key=$value";
            });
            $name .= '?'.implode("&", $filters);
        }

        return $name;
    }

    public function getClient() : BayeuxClient
    {
        return $this->streamingClient;
    }
}
