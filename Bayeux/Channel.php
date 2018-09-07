<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 9:59 AM
 */

namespace AE\ConnectBundle\Bayeux;

use Doctrine\Common\Collections\ArrayCollection;

class Channel implements ChannelInterface
{
    /**
     * @var string
     */
    private $channelId;

    /**
     * @var ArrayCollection
     */
    private $listeners;

    /**
     * @var ArrayCollection
     */
    private $subscribers;

    /**
     * @var BayeuxClient
     */
    private $client;

    public function __construct(BayeuxClient $client, string $channelId)
    {
        $this->client      = $client;
        $this->channelId   = $channelId;
        $this->listeners   = new ArrayCollection();
        $this->subscribers = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getChannelId(): string
    {
        return $this->channelId;
    }

    public function notifyMessageListeners(Message $message)
    {
        $this->subscribers->forAll(function ($consumer) use ($message) {
            call_user_func($consumer, $this, $message->getData());
        });
    }

    public function subscribe(callable $consumer)
    {
        if (!$this->subscribers->contains($consumer)) {
            $this->subscribers->add($consumer);
        }
    }

    public function unsubscribe(callable $consumer)
    {
        if ($this->subscribers->contains($consumer)) {
            $this->subscribers->removeElement($consumer);
        }
    }

    public function unsubscribeAll()
    {
        $this->subscribers->clear();
    }
}
