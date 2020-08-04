<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:20 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Enqueue\Extension;

use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use Enqueue\Consumption\Context\End;
use Enqueue\Consumption\Context\InitLogger;
use Enqueue\Consumption\Context\MessageReceived;
use Enqueue\Consumption\Context\MessageResult;
use Enqueue\Consumption\Context\PostConsume;
use Enqueue\Consumption\Context\PostMessageReceived;
use Enqueue\Consumption\Context\PreConsume;
use Enqueue\Consumption\Context\PreSubscribe;
use Enqueue\Consumption\Context\ProcessorException;
use Enqueue\Consumption\Context\Start;
use Enqueue\Consumption\ExtensionInterface;
use Psr\Log\LoggerAwareTrait;

class SalesforceOutboundExtension implements ExtensionInterface
{
    use LoggerAwareTrait;

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
        $this->idleWindow    = $idleWindow;
    }

    /**
     * @inheritDoc
     */
    public function onStart(Start $context): void
    {
        // send any queued messages
        $this->outboundQueue->send();
    }

    /**
     * @inheritDoc
     */
    public function onResult(MessageResult $context): void
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onEnd(End $context): void
    {
        // Send what you can
        $this->outboundQueue->send();
        $this->lastMessageReceived = null;
    }

    /**
     * @inheritDoc
     */
    public function onInitLogger(InitLogger $context): void
    {
        $this->setLogger($context->getLogger());
    }

    /**
     * @inheritDoc
     */
    public function onMessageReceived(MessageReceived $context): void
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onPostConsume(PostConsume $context): void
    {
        $now = new \DateTime();
        $lastMessageReceived = $this->lastMessageReceived ? $this->lastMessageReceived : new \DateTime();
        $then = (clone $lastMessageReceived)->add(
            \DateInterval::createFromDateString($this->idleWindow)
        );
        if (null !== $this->lastMessageReceived && ($now >= $then || $this->outboundQueue->count() > 800)) {
            $this->outboundQueue->send();
        }
    }

    /**
     * @inheritDoc
     */
    public function onPostMessageReceived(PostMessageReceived $context): void
    {
        $this->lastMessageReceived = new \DateTime();
    }

    /**
     * @inheritDoc
     */
    public function onPreConsume(PreConsume $context): void
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onPreSubscribe(PreSubscribe $context): void
    {
        // Nothing to do here
    }

    /**
     * @inheritDoc
     */
    public function onProcessorException(ProcessorException $context): void
    {
        $this->logger->debug('SalesforceOutboundExtension->ProcessorException. '.$context->getException()->getTraceAsString());
    }
}
