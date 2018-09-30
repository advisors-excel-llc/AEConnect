<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/30/18
 * Time: 3:06 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use JMS\Serializer\Annotation as Serializer;

abstract class AbstractSubscriber implements ChannelSubscriberInterface
{
    /**
     * @var ConsumerInterface[]|array
     * @Serializer\Exclude()
     */
    protected $subscribers;

    public function __construct()
    {
        $this->subscribers = new ArrayCollection();
    }

    public function addSubscriber(ConsumerInterface $consumer)
    {
        if (!$this->subscribers->contains($consumer)) {
            $this->subscribers->add($consumer);
        }
    }

    public function getSubscribers(): array
    {
        return $this->subscribers->getValues();
    }
}
