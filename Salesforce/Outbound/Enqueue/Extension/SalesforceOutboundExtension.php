<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:20 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Enqueue\Extension;

use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use Enqueue\Consumption\Context;
use Enqueue\Consumption\ExtensionInterface;

class SalesforceOutboundExtension implements ExtensionInterface
{
    /**
     * @var OutboundQueue
     */
    private $outboundQueue;

    /**
     * @var \DateTime
     */
    private $lastMessageReceived;

    private $idleWindow = '10 seconds';

    public function __construct(OutboundQueue $queue, string $idleWindow = '10 seconds')
    {
        $this->outboundQueue = $queue;
        $this->idleWindow = $idleWindow;
    }

    /**
     * @inheritDoc
     */
    public function onStart(Context $context)
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onBeforeReceive(Context $context)
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onPreReceived(Context $context)
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onResult(Context $context)
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onPostReceived(Context $context)
    {
        $this->lastMessageReceived = new \DateTime();
    }

    /**
     * @inheritDoc
     */
    public function onIdle(Context $context)
    {
        $now = new \DateTime();
        $then = (clone $this->lastMessageReceived)->add(\DateInterval::createFromDateString($this->idleWindow));
        if (null !== $this->lastMessageReceived && ($now >= $then || $this->outboundQueue->count() > 1000)) {
            $this->outboundQueue->send();
        }
    }

    /**
     * @inheritDoc
     */
    public function onInterrupted(Context $context)
    {
        // Nothing to do here
    }

}
