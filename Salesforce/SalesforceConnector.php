<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 5:13 PM
 */

namespace AE\ConnectBundle\Salesforce;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\MessagePayload;
use AE\ConnectBundle\Salesforce\Outbound\ReferenceIdGenerator;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\UnitOfWork;
use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Validator\Validation;
use Doctrine\Common\Util\ClassUtils;

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
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var Transformer
     */
    private $transformer;

    /**
     * @var Validation
     */
    private $validator;

    /**
     * @var ProducerInterface
     */
    private $producer;

    public function __construct(
        string $topicPrefix,
        ManagerRegistry $registry,
        ConnectionManagerInterface $connectionManager,
        Transformer $transformer,
        Validation $validation,
        ProducerInterface $producer
    ) {
        $this->topic             = $topicPrefix;
        $this->registry          = $registry;
        $this->connectionManager = $connectionManager;
        $this->transformer       = $transformer;
        $this->validator         = $validation;
        $this->producer          = $producer;
    }

    /**
     * @param $entity
     * @param string $connectionName
     *
     * @return bool
     */
    public function send($entity, string $connectionName = 'default'): bool
    {
        $className = ClassUtils::getRealClass($entity);
        /** @var EntityManager $manager */
        $manager       = $this->registry->getManagerForClass($className);
        $classMetadata = $manager->getClassMetadata($className);
        $connection    = $this->connectionManager->getConnection($connectionName);
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);

        // This entity is not using this connection
        if (null === $metadata) {
            return false;
        }

        $fields = $metadata->getFieldMap();
        $uow    = $manager->getUnitOfWork();

        $changeSet = $uow->getEntityChangeSet($entity);

        // If this is fired from a listener, the $changeSet will have values.
        // Otherwise, we need to compute the change set
        if (empty($changeSet)) {
            $uow->computeChangeSet($classMetadata, $entity);
            $changeSet = $uow->getEntityChangeSet($entity);
        }

        // TODO: Validate Entity prior to mapping

        $sObject = new CompositeSObject($metadata->getSObjectType());

        foreach ($metadata->getIdentifyingFields() as $prop => $field) {
            $sObject->$field = $classMetadata->getFieldValue($entity, $prop);
        }

        $refId = ReferenceIdGenerator::create($entity, $metadata);

        $intent = UnitOfWork::STATE_REMOVED === $uow->getEntityState($entity)
            ? self::INTENT_DELETE
            : (null === $sObject->Id ? self::INTENT_INSERT : self::INTENT_UPDATE);

        switch ($intent) {
            case self::INTENT_INSERT:
                foreach ($fields as $property => $field) {
                    $payload = TransformerPayload::outbound();
                    $payload->setPayload($classMetadata->getFieldValue($entity, $property))
                            ->setPropertyName($property)
                            ->setEntity($entity)
                            ->setMetadata($metadata)
                            ->setClassMetadata($classMetadata)
                            ->setRefId($refId)
                    ;

                    $sObject->$field = $payload->getPayload();
                }
                break;
            case self::INTENT_UPDATE:
                foreach ($fields as $property => $field) {
                    if (array_key_exists($property, $changeSet)) {
                        $payload = TransformerPayload::outbound();
                        $payload->setPayload($changeSet[$property][1])
                                ->setPropertyName($property)
                                ->setEntity($entity)
                                ->setMetadata($metadata)
                                ->setClassMetadata($classMetadata)
                                ->setRefId($refId)
                        ;
                        // Get the new value
                        $this->transformer->transformOutbound($payload);
                        $sObject->$field = $payload->getPayload();
                    } elseif (ucwords($field) === 'Id'
                        && null !== ($id = $classMetadata->getFieldValue($entity, $property))) {
                        $sObject->Id = $id;
                    }
                }
                break;
            case self::INTENT_DELETE:
                $field = $metadata->getPropertyByField('Id');

                if (null === $field) {
                    return false;
                }

                $id = $classMetadata->getFieldValue($entity, $field);

                if (null === $id) {
                    return false;
                }

                $sObject->Id = $id;
        }

        if (self::INTENT_DELETE !== $intent) {
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
            $connection->getRestClient()->getSerializer()->serialize($messagePayload, 'json'),
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
