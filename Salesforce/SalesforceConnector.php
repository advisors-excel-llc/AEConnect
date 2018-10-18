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
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\SalesforceRestSdk\Model\SObject;
use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use JMS\Serializer\SerializerInterface;

class SalesforceConnector
{
    /**
     * @var SObjectCompiler
     */
    private $compiler;

    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(
        ProducerInterface $producer,
        SObjectCompiler $compiler,
        SerializerInterface $serializer
    ) {
        $this->producer   = $producer;
        $this->compiler   = $compiler;
        $this->serializer = $serializer;
    }

    /**
     * @param $entity
     * @param string $connectionName
     *
     * @return bool
     */
    public function send($entity, string $connectionName = 'default'): bool
    {
        $result  = $this->compiler->compile($entity, $connectionName);
        $intent  = $result->getIntent();
        $sObject = $result->getSObject();

        if (CompilerResult::DELETE !== $intent) {
            // If there are no fields other than Id and Type set, don't sync
            $fields = array_diff(['Id', 'Type'], array_keys($sObject->getFields()));
            if (empty($fields)) {
                return false;
            }
        }

        $message = new Message(
            $this->serializer->serialize($result, 'json')
        );
        $this->producer->sendEvent(OutboundProcessor::TOPIC, $message);

        return true;
    }

    public function receive(SObject $object, string $connectionName = 'default')
    {

    }
}
