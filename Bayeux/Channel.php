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
        $this->client = $client;
        $this->channelId = $channelId;
        $this->listeners = new ArrayCollection();
        $this->subscribers = new ArrayCollection();
    }

    public function notifyMessageListeners(Message $message)
    {
        // TODO: Implement notifyMessageListeners() method.
    }
}
