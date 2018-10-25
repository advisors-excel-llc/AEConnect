<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 10:29 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\JobInfo;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class InboundBulkQueue
{
    use LoggerAwareTrait;

    /**
     * @var SObjectTreeMaker
     */
    private $treeMaker;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(
        SObjectTreeMaker $treeMaker,
        SalesforceConnector $connector,
        RegistryInterface $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->treeMaker  = $treeMaker;
        $this->connector  = $connector;
        $this->registry   = $registry;

        if (null !== $logger) {
            $this->setLogger($logger);
        }
    }

    public function process(ConnectionInterface $connection, bool $updateEntities = false)
    {
        $map = $this->treeMaker->buildFlatMap($connection);

        foreach ($map as $type) {
            $this->startJob($connection, $type, $updateEntities);
        }
    }

    private function startJob(ConnectionInterface $connection, string $objectType, bool $updateEntities)
    {
        $fields           = [];
        $metadataRegistry = $connection->getMetadataRegistry();

        foreach ($metadataRegistry->findMetadataBySObjectType($objectType) as $metadata) {
            foreach ($metadata->getPropertyMap() as $field) {
                if (false === array_search($field, $fields)) {
                    $fields[] = $field;
                }
            }
        }

        if (empty($fields)) {
            return;
        }

        try {
            $query  = "SELECT ".implode(',', $fields)." FROM $objectType";
            $client = $connection->getBulkClient();
            $job    = $client->createJob($objectType, "query", JobInfo::TYPE_JSON);
            $batch  = $client->addBatch($job, $query);

            do {
                $batchStatus = $client->getBatchStatus($job->getId(), $batch->getId());
                sleep(10);
            } while (BatchInfo::STATE_COMPLETED !== $batchStatus->getState());

            $batchResults = $client->getBatchResults($job->getId(), $batch->getId());

            foreach ($batchResults as $resultId) {
                $result = $client->getResult($job->getId(), $batch->getId(), $resultId);

                if (null !== $result) {
                    $this->saveResult($result, $objectType, $connection, $updateEntities);
                }
            }

            $client->closeJob($job->getId());
        } catch (\Exception $e) {
            if (null !== $this->logger) {
                $this->logger->warning($e->getMessage());
                $this->logger->debug($e->getTraceAsString());
            }
        }
    }

    private function saveResult(
        string $result,
        string $objectType,
        ConnectionInterface $connection,
        bool $updateEntities
    ) {
        $serializer = $connection->getRestClient()->getSerializer();
        $objects    = $serializer->deserialize(
            $result,
            'array<'.CompositeSObject::class.'>',
            'json'
        );

        foreach ($objects as $object) {
            $object->Type = $objectType;
            $this->preProcess($object, $connection, $updateEntities);
            $this->connector->receive($object, SalesforceConsumerInterface::UPDATED, $connection->getName());
        }
    }

    private function preProcess(CompositeSObject $object, ConnectionInterface $connection, bool $updateEntities)
    {
        if (true === $updateEntities) {
            return;
        }

        $metadataRegistry = $connection->getMetadataRegistry();
        $values           = [];

        foreach ($metadataRegistry->findMetadataBySObjectType($object->Type) as $metadata) {
            $class       = $metadata->getClassName();
            $manager     = $this->registry->getManagerForClass($class);
            $identifiers = $metadata->getIdentifiers();
            $ids         = [];

            foreach ($identifiers as $identifier) {
                $ids[$identifier->getProperty()] = $identifier->getField();
            }

            $entity = $manager->getRepository($class)->findOneBy($ids);

            // Found an entity, need pull off the identifying information from it, forget the rest
            if (null !== $entity) {
                foreach ($metadata->getPropertyMap() as $prop => $field) {
                    // Since we're not updating, we still want to update the ID
                    if ('id' === strtolower($field) || $metadata->isIdentifier($prop)) {
                        $values[$field] = $object->$field;
                    }
                }
            }
        }

        // If we have values to change, then we change them
        // If the object is new, $values will be empty and so we don't want to affect the incoming data
        if (!empty($values)) {
            $object->setFields($values);
        }
    }
}
