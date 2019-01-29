<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 4:21 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Enqueue;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use Enqueue\Client\TopicSubscriberInterface;
use Enqueue\Consumption\Result;
use Enqueue\Fs\FsMessage;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use JMS\Serializer\SerializerInterface;

/**
 * Class OutboundProcessor
 *
 * @package AE\ConnectBundle\Salesforce\Outbound\Enqueue
 */
class OutboundProcessor implements Processor, TopicSubscriberInterface
{

    public const TOPIC = 'ae_connect';

    /**
     * @var OutboundQueue
     */
    private $queue;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(
        OutboundQueue $queue,
        SerializerInterface $serializer
    ) {
        $this->serializer = $serializer;
        $this->queue      = $queue;
    }

    /**
     * @inheritDoc
     *
     * @param FsMessage $message
     */
    public function process(Message $message, Context $context): string
    {
        /** @var CompilerResult $payload */
        $payload = $this->serializer->deserialize(
            $message->getBody(),
            CompilerResult::class,
            'json'
        );
        $this->queue->add($payload);

        return Result::ACK;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedTopics()
    {
        return [self::TOPIC];
    }
}
