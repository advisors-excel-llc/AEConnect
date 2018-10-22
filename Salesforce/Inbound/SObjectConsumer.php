<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 3:08 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound;

use AE\ConnectBundle\Connection\ConnectionTrait;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Streaming\ChannelSubscriberInterface;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;
use AE\SalesforceRestSdk\Bayeux\Message;
use AE\SalesforceRestSdk\Model\SObject;

class SObjectConsumer implements ConsumerInterface
{
    use ConnectionTrait;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    public function __construct(SalesforceConnector $connector)
    {
        $this->connector = $connector;
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        if ($message->isSuccessful()) {
            $data = $message->getData();

            if (preg_match('/^\/data\/(.*?)ChangeEvent$/', $channel->getChannelId())) {
                $payload = $data->getPayload();
                if (null === $payload) {
                    return;
                }
                $changeEventHeader = $payload['ChangeEventHeader'];
                unset($payload['ChangeEventHeader']);

                $intent = $changeEventHeader['changeType'];
                switch ($intent) {
                    case "CREATE":
                        $intent = ChannelSubscriberInterface::CREATED;
                        break;
                    case "UPDATE":
                        $intent = ChannelSubscriberInterface::UPDATED;
                        break;
                    case "DELETE":
                        $intent = ChannelSubscriberInterface::DELETED;
                        break;
                }
                $sObject = new SObject(
                    $payload + [
                        'Type' => $changeEventHeader['entityType'],
                        'Id'   => $changeEventHeader['recordIds'][0],
                    ]
                );
                $this->connector->receive($sObject, $intent, $this->getConnection()->getName());
            } else {
                $intent  = strtoupper($data->getEvent()->getType());
                $sObject = $data->getSobject();

                $this->connector->receive($sObject, $intent, $this->getConnection()->getName());
            }
        }
    }

    public function getPriority(): ?int
    {
        return 0;
    }
}
