<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 3:08 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound;

use AE\ConnectBundle\Connection\ConnectionsTrait;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Message;
use AE\SalesforceRestSdk\Bayeux\Salesforce\Event;
use AE\SalesforceRestSdk\Model\SObject;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SObjectConsumer implements SalesforceConsumerInterface
{
    use ConnectionsTrait;
    use LoggerAwareTrait;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    public function __construct(SalesforceConnector $connector, ?LoggerInterface $logger = null)
    {
        $this->connector = $connector;

        $this->setLogger($logger ?: new NullLogger());
    }

    public function channels(): array
    {
        return [
            'objects' => '*',
            'topics'  => '*',
        ];
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        $data = $message->getData();

        $this->logger->debug(
            "LISTENER RECEIVED: Channel `{channel}` | {data}",
            [
                'channel' => $channel->getChannelId(),
                'data'    => $message->getData(),
            ]
        );

        if (null !== $data) {
            if (null !== $data->getSobject()) {
                $this->consumeTopic($data->getSobject(), $data->getEvent());
            } elseif (null !== $data->getPayload() && is_array($data->getPayload())) {
                $this->consumeChangeEvent($data->getPayload());
            }
        }
    }

    public function getPriority(): ?int
    {
        return 0;
    }

    private function consumeChangeEvent(array $payload)
    {
        $changeEventHeader = $payload['ChangeEventHeader'];
        unset($payload['ChangeEventHeader']);

        $intent = $changeEventHeader['changeType'];
        $origin = $changeEventHeader['changeOrigin'];

        if (false !== ($pos = strpos($origin, ';'))) {
            $origin = substr($origin, $pos + 8); // ;client=$origin
        }

        switch ($intent) {
            case "CREATE":
                $intent = SalesforceConsumerInterface::CREATED;
                break;
            case "UPDATE":
                $intent = SalesforceConsumerInterface::UPDATED;
                break;
            case "DELETE":
                $intent = SalesforceConsumerInterface::DELETED;
                break;
            case "UNDELETE":
                $intent = SalesforceConsumerInterface::UNDELETED;
                break;
            default:
                return;
        }

        $sObject = new SObject(
            $payload + [
                '__SOBJECT_TYPE__' => $changeEventHeader['entityName'],
                'Id'               => $changeEventHeader['recordIds'][0],
            ]
        );

        // Find Compound Fields in the object and assign their nexted values back to the SObject
        foreach ($payload as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $subField => $innerValue) {
                    if (null === $sObject->$subField) {
                        $sObject->$subField = $innerValue;
                    }
                }
            }
        }

        foreach ($this->connections as $connection) {
            if ($origin === $connection->getAppName() &&
                !in_array(
                    $changeEventHeader['entityName'],
                    $connection->getPermittedFilteredObjects()
                )
            ) {
                continue;
            }

            $this->connector->receive($sObject, $intent, $connection->getName());
        }
    }

    private function consumeTopic(SObject $object, Event $event)
    {
        if (null !== $object->__SOBJECT_TYPE__) {
            $intent = strtoupper($event->getType());

            foreach ($this->connections as $connection) {
                $this->connector->receive($object, $intent, $connection->getName());
            }
        }
    }
}
