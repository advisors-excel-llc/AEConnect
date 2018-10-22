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
use AE\SalesforceRestSdk\Model\SObject;

class SObjectConsumer implements SalesforceConsumerInterface
{
    use ConnectionsTrait;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    public function __construct(SalesforceConnector $connector)
    {
        $this->connector = $connector;
    }

    public function channels(): array
    {
        return [
            'objects' => '*',
        ];
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        $data = $message->getData();

        if (null !== $data) {
            $payload = $data->getPayload();

            if (!is_array($payload)) {
                return;
            }

            $changeEventHeader = $payload['ChangeEventHeader'];
            unset($payload['ChangeEventHeader']);

            $intent = $changeEventHeader['changeType'];

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
            }

            $sObject = new SObject(
                $payload + [
                    'Type' => $changeEventHeader['entityName'],
                    'Id'   => $changeEventHeader['recordIds'][0],
                ]
            );

            foreach ($this->connections as $connection) {
                $this->connector->receive($sObject, $intent, $connection->getName());
            }
        }
    }

    public function getPriority(): ?int
    {
        return 0;
    }
}
