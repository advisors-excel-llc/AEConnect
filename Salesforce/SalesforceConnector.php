<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 5:13 PM
 */

namespace AE\ConnectBundle\Salesforce;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\MessagePayload;
use AE\SalesforceRestSdk\Model\SObject;
use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use JMS\Serializer\SerializerInterface;

class SalesforceConnector
{
    public const INTENT_INSERT = "INSERT";
    public const INTENT_UPDATE = "UPDATE";
    public const INTENT_DELETE = "DELETE";
    /**
     * @var string
     */
    private $topic;

    /**
     * @var SObjectCompiler
     */
    private $comiler;

    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(
        string $topicPrefix,
        ProducerInterface $producer,
        SObjectCompiler $compiler
    ) {
        $this->topic    = $topicPrefix;
        $this->producer = $producer;
        $this->comiler  = $compiler;
    }

    /**
     * @param $entity
     * @param string $connectionName
     *
     * @return bool
     */
    public function send($entity, string $connectionName = 'default'): bool
    {
        $result   = $this->comiler->compile($entity, $connectionName);
        $intent   = $result->getIntent();
        $sObject  = $result->getSObject();
        $metadata = $result->getMetadata();
        $refId    = $result->getReferenceId();

        if (CompilerResult::DELETE !== $intent) {
            // If there are no fields other than Id and Type set, don't sync
            $fields = array_diff(['Id', 'Type'], $sObject->getFields());
            if (empty($fields)) {
                return false;
            }
        }

        $messagePayload = new MessagePayload();
        $messagePayload->setMetadata($metadata)
                       ->setSobject($sObject)
        ;

        $message = new Message(
            $this->serializer->serialize($messagePayload, 'json'),
            [
                'connection' => $connectionName,
                'intent'     => $intent,
                'created'    => new \DateTime(),
                'refId'      => $refId,
            ]
        );
        $this->producer->sendEvent($this->topic, $message);

        return true;
    }

    public function receive(SObject $object, string $connectionName = 'default')
    {

    }
}
