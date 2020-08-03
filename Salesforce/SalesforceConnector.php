<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 5:13 PM.
 */

namespace AE\ConnectBundle\Salesforce;

use AE\ConnectBundle\Salesforce\Inbound\Compiler\EntityCompiler;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SalesforceConnector implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use GetEmTrait;

    /**
     * @var SObjectCompiler
     */
    private $sObjectCompiler;

    /**
     * @var EntityCompiler
     */
    private $entityCompiler;

    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var bool
     */
    private $enabled = true;

    public function __construct(
        ProducerInterface $producer,
        SObjectCompiler $sObjectCompiler,
        EntityCompiler $entityCompiler,
        SerializerInterface $serializer,
        ManagerRegistry $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->producer = $producer;
        $this->sObjectCompiler = $sObjectCompiler;
        $this->entityCompiler = $entityCompiler;
        $this->serializer = $serializer;
        $this->registry = $registry;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param $entity
     */
    public function send($entity, string $connectionName = 'default'): bool
    {
        if (!$this->enabled) {
            $this->logger->debug('#SC11 Connector is disabled for {conn}', ['conn' => $connectionName]);
            return false;
        }

        try {
            $result = $this->sObjectCompiler->compile($entity, $connectionName);
        } catch (\RuntimeException $e) {
            $this->logger->warning('#SC12 Runtime Exception for Send. '.$e->getMessage());
            return false;
        }

        return $this->sendCompilerResult($result);
    }

    /**
     * @param string $connectionName
     */
    public function sendCompilerResult(CompilerResult $result): bool
    {
        $intent = $result->getIntent();
        $sObject = $result->getSObject();

        if (CompilerResult::DELETE !== $intent) {
            // If there are no fields other than Id set, don't sync
            $fields = array_diff(array_keys($sObject->getFields()), ['Id']);
            if (empty($fields)) {
                $this->logger->debug(
                    '#SC21 No fields for object {type} to insert or update for {conn}',
                    [
                        'type' => $sObject->getType(),
                        'conn' => $result->getConnectionName(),
                    ]
                );

                return false;
            }
        }

        $message = new Message(
            $this->serializer->serialize($result, 'json')
        );
        $this->producer->sendEvent(OutboundProcessor::TOPIC, $message);

        return true;
    }

    /**
     * @param $object
     * @param bool $validate
     *
     * @throws MappingException
     * @throws ORMException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     */
    public function receive($object, string $intent, string $connectionName = 'default', $validate = true, $deliveryMethod = ''): bool
    {
        $this->logger->debug('#SC31 $intent = '.$intent.' - $deliveryMethod = '.$deliveryMethod);
        if (!$this->enabled) {
            return false;
        }

        if (!is_array($object)) {
            $object = [$object];
        }

        try {
            $entities = [];
            foreach ($object as $obj) {
                $this->logger->debug('#SC32 $deliveryMethod = '.$deliveryMethod);
                $entities = array_merge($entities, $this->entityCompiler->compile($obj, $connectionName, $validate, $deliveryMethod));
            }
        } catch (\RuntimeException $e) {
            $this->logger->warning('#SC33 Runtime Exception for Receive. '.$e->getMessage());
            $this->logger->debug('#SC33 Runtime Exception for Receive. '.$e->getTraceAsString());
            return false;
        }

        // Attempt to save all entities in as few transactions as possible
        $this->logger->debug('#SC34 Receive complete for: $intent = '.$intent.' - count($entities) = '.count($entities));
        $this->saveEntitiesToDB($intent, $entities);

        return true;
    }

    /**
     * @return $this
     */
    public function enable()
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function disable()
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * @param $entities
     *
     * @throws ORMException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     */
    private function saveEntitiesToDB(string $intent, $entities, bool $transactional = true): void
    {
        $this->logger->debug('#SC41 Attempting '.$intent.', to Save Entities to DB.');
        if (!is_array($entities)) {
            $entities = [$entities];
        }

        $entityMap = [];

        foreach ($entities as $entity) {
            $class = ClassUtils::getClass($entity);
            $manager = $this->getEm($class, $this->registry, true);

            switch ($intent) {
                case SalesforceConsumerInterface::CREATED:
                    $this->logger->debug('#SC42 Doing a CREATED persist().');
                    $manager->persist($entity);
                    break;
                case SalesforceConsumerInterface::UPDATED:
                case SalesforceConsumerInterface::UNDELETED:
                    $this->logger->debug('#SC42 Doing an UPDATED persist().');
                    $manager->persist($entity);
                    break;
                case SalesforceConsumerInterface::DELETED:
                    $this->logger->debug('#SC42 Doing a DELETED remove().');
                    $manager->remove($manager->merge($entity));
                    break;
            }

            if ($transactional) {
                // When running as transactional, we need to keep track of things in case of an error
                $entityMap[$intent][] = $entity;
            } else {
                // If not running transactional, flush the entity now
                try {
                    $this->logger->debug('#SC43 Trying to flush the entity, which is a persist to the database.');
                    $manager->flush();
                } catch (\Throwable $t) {
                    // If an error occurs, log it and carry on
                    $this->logger->warning('#SC46 Throwable error: '.$t->getMessage());
                } finally {
                    // Clear memory to prevent buildup
                    $this->logger->debug('#SC44 Clear memory.');
                    $manager->clear($class);
                }
            }

            $this->logger->debug('#SC45 {intent} {entity}', ['intent' => $intent, 'entity' => $entity->__toString()]);
        }

        // In a transactional run, run through each of the managers for a class (in case they differ) and flush the
        // contents
        if ($transactional && isset($this->ems) && is_array($this->ems)) {
            $this->logger->debug('#SC46 This is a transactional run.');
            foreach ($this->ems as $manager) {
                try {
                    $manager->transactional(
                        function (EntityManagerInterface $em) {
                            $em->flush();
                            $em->clear();
                        }
                    );
                } catch (\Throwable $t) {
                    $this->logger->warning('#SC47 Throwable Error for Transaction. '.$t->getMessage());
                    // Clear the current entity manager to save memory
                    $manager->clear();
                    // If a transaction fails, try to save entries one by one
                    foreach ($entityMap as $intent => $ens) {
                        $this->logger->debug('#SC48 Transactional, we are trying to save entries one by one.');
                        $this->saveEntitiesToDB($intent, $ens, false);
                    }
                }
            }
        }
    }
}
