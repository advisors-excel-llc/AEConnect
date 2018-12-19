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
use AE\SalesforceRestSdk\Model\Rest\Count;
use AE\SalesforceRestSdk\Model\SObject;
use AE\SalesforceRestSdk\Psr7\CsvStream;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
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

    /**
     * InboundBulkQueue constructor.
     *
     * @param SObjectTreeMaker $treeMaker
     * @param SalesforceConnector $connector
     * @param RegistryInterface $registry
     * @param null|LoggerInterface $logger
     */
    public function __construct(
        SObjectTreeMaker $treeMaker,
        SalesforceConnector $connector,
        RegistryInterface $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->treeMaker = $treeMaker;
        $this->connector = $connector;
        $this->registry  = $registry;

        $this->setLogger($logger ?: new NullLogger());
    }

    public function process(ConnectionInterface $connection, array $types = [], bool $updateEntities = false)
    {
        $map = $this->treeMaker->buildFlatMap($connection);

        if (!empty($types)) {
            $map = array_intersect($map, $types);
        }

        $counts = $connection->getRestClient()->count($map);

        foreach ($counts as $count) {
            $this->startJob($connection, $count, $updateEntities);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param Count $count
     * @param bool $updateEntities
     */
    private function startJob(ConnectionInterface $connection, Count $count, bool $updateEntities)
    {
        $objectType       = $count->getName();
        $fields           = [];
        $recordTypes      = [];
        $metadataRegistry = $connection->getMetadataRegistry();
        $i = 0;

        foreach ($metadataRegistry->findMetadataBySObjectType($objectType) as $metadata) {
            if (!$metadata->getDescribe()->isQueryable()) {
                continue;
            }

            foreach ($metadata->getPropertyMap() as $field) {
                if (false === array_search($field, $fields)) {
                    $fields[] = $field;
                }
            }

            // If the metadata has a class-level RecordType annotation, let's use it to filter
            // but the moment there's metadata for the same type that doesn't have a class-level
            // RecordType annotation, we need to get records of any record type and filter them out
            // locally
            if (in_array('RecordTypeId', $fields)) {
                $recordType = $metadata->getRecordType();
                if (null !== $recordType && null !== $recordType->getName() && ($i === 0 || !empty($recordTypes))) {
                    $recordTypes[] = $metadata->getRecordTypeId($recordType->getName());
                } else {
                    $recordTypes = [];
                }
            } else {
                $recordTypes = [];
            }

            ++$i;
        }

        if (empty($fields)) {
            return;
        }

        try {
            $query = "SELECT ".implode(',', $fields)." FROM $objectType";

            if (!empty($recordTypes)) {
                $query .= " WHERE RecordTypeId IN ('".implode("', '", $recordTypes)."')";
            }

            if ($count->getCount() >= $connection->getBulkApiMinCount()) {
                $this->processInBulk($connection, $objectType, $updateEntities, $query);
            } else {
                $this->processComposite($connection, $objectType, $updateEntities, $query);
            }
        } catch (\Exception $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
        } catch (GuzzleException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
        }
    }

    /**
     * @param CsvStream $result
     * @param string $objectType
     * @param ConnectionInterface $connection
     * @param bool $updateEntities
     */
    private function saveBulkResult(
        CsvStream $result,
        string $objectType,
        ConnectionInterface $connection,
        bool $updateEntities
    ) {
        $fields  = [];
        $count   = 0;
        $objects = [];

        while (!$result->eof()) {
            $row = $result->read();
            if (empty($fields)) {
                $fields = $row;
                continue;
            }

            $object = new CompositeSObject($objectType);
            foreach ($row as $i => $value) {
                $object->{$fields[$i]} = $value;
            }
            $object->__SOBJECT_TYPE__ = $objectType;
            $this->preProcess($object, $connection, $updateEntities);
            $objects[] = $object;

            // Gotta break this up a little to precent 10000000s of records from being saved at once
            if (count($objects) === 200) {
                $this->connector->enable();
                $this->connector->receive($objects, SalesforceConsumerInterface::UPDATED, $connection->getName());
                $this->connector->disable();
                $objects = [];
            }
            ++$count;
        }

        if (!empty($objects)) {
            $this->connector->enable();
            $this->connector->receive($objects, SalesforceConsumerInterface::UPDATED, $connection->getName());
            $this->connector->disable();
        }

        $this->logger->info('Processed {count} {type} objects.', ['count' => $count, 'type' => $objectType]);
    }

    /**
     * @param SObject $object
     * @param ConnectionInterface $connection
     * @param bool $updateEntities
     */
    private function preProcess(SObject $object, ConnectionInterface $connection, bool $updateEntities)
    {
        if (true === $updateEntities) {
            return;
        }

        $metadataRegistry = $connection->getMetadataRegistry();
        $values           = [];

        foreach ($metadataRegistry->findMetadataBySObjectType($object->__SOBJECT_TYPE__) as $metadata) {
            $class         = $metadata->getClassName();
            $manager       = $this->registry->getManagerForClass($class);
            $classMetadata = $manager->getClassMetadata($class);
            $ids           = [];

            foreach ($metadata->getIdentifyingFields() as $prop => $field) {
                $value = $object->$field;
                if (null !== $value && is_string($value) && strlen($value) > 0) {
                    if ($classMetadata->getTypeOfField($prop) instanceof UuidType) {
                        $value = Uuid::fromString($value);
                    }
                }
                $ids[$prop] = $value;
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

    /**
     * @param ConnectionInterface $connection
     * @param string $objectType
     * @param bool $updateEntities
     * @param $query
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function processInBulk(
        ConnectionInterface $connection,
        string $objectType,
        bool $updateEntities,
        $query
    ): void {
        $client = $connection->getBulkClient();
        $job    = $client->createJob($objectType, JobInfo::QUERY, JobInfo::TYPE_CSV);

        $this->logger->info(
            'Bulk Job (ID# {job}) started for SObject Type {type}',
            [
                'job'  => $job->getId(),
                'type' => $objectType,
            ]
        );

        $batch = $client->addBatch($job, $query);

        $this->logger->info(
            'Batch (ID# {batch}) added to Job (ID# {job})',
            [
                'job'   => $job->getId(),
                'batch' => $batch->getId(),
            ]
        );

        do {
            $batchStatus = $client->getBatchStatus($job, $batch->getId());
            sleep(10);
        } while (BatchInfo::STATE_COMPLETED !== $batchStatus->getState());

        $this->logger->info(
            'Batch (ID# {batch}) for Job (ID# {job}) is complete',
            [
                'job'   => $job->getId(),
                'batch' => $batch->getId(),
            ]
        );

        $batchResults = $client->getBatchResults($job, $batch->getId());

        foreach ($batchResults as $resultId) {
            $result = $client->getResult($job, $batch->getId(), $resultId);

            if (null !== $result) {
                $this->saveBulkResult($result, $objectType, $connection, $updateEntities);
            }
        }

        $client->closeJob($job);

        $this->logger->info(
            'Job (ID# {job}) is now closed',
            [
                'job' => $job->getId(),
            ]
        );
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $sObjectType
     * @param bool $updateEntity
     * @param string $query
     *
     * @throws GuzzleException
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     */
    public function processComposite(
        ConnectionInterface $connection,
        string $sObjectType,
        bool $updateEntity,
        string $query
    ) {
        $client = $connection->getRestClient()->getSObjectClient();
        $query  = $client->query($query);
        do {
            $records = $query->getRecords();
            if (!empty($records)) {
                foreach ($records as $record) {
                    $record->__SOBJECT_TYPE__ = $sObjectType;
                    $this->preProcess($record, $connection, $updateEntity);
                }
                $this->connector->enable();
                $this->connector->receive($records, SalesforceConsumerInterface::UPDATED, $connection->getName());
                $this->connector->disable();
            }
        } while (!($query = $client->query($query))->isDone());
    }
}
