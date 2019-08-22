<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:39 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\JobInfo;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use AE\SalesforceRestSdk\Psr7\CsvStream;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class BulkApiProcessor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var BulkPreprocessor
     */
    private $preProcessor;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var int
     */
    private $batchSize = 50;

    public function __construct(BulkPreprocessor $preprocessor, SalesforceConnector $connector, int $batchSize = 50)
    {
        $this->preProcessor = $preprocessor;
        $this->connector    = $connector;
        $this->logger       = new NullLogger();
        $this->batchSize    = $batchSize;
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $objectType
     * @param $query
     * @param bool $updateEntities
     * @param bool $insertEntities
     *
     * @throws MappingException
     * @throws ORMException
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(
        ConnectionInterface $connection,
        string $objectType,
        $query,
        bool $updateEntities,
        bool $insertEntities = false
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
            if (BatchInfo::STATE_COMPLETED !== $batchStatus->getState()) {
                sleep(10);
            }
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
                $this->save($result, $objectType, $connection, $updateEntities, $insertEntities);
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
     * @param CsvStream $result
     * @param string $objectType
     * @param ConnectionInterface $connection
     * @param bool $updateEntities
     * @param bool $insertEntities
     *
     * @throws MappingException
     * @throws ORMException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     */
    public function save(
        CsvStream $result,
        string $objectType,
        ConnectionInterface $connection,
        bool $updateEntities,
        bool $insertEntities = false
    ) {
        $count   = 0;
        $objects = [];

        foreach ($result->getContents(true) as $row) {
            $object = new CompositeSObject($objectType);
            foreach ($row as $field => $value) {
                $object->{$field} = $value;
            }
            $object->__SOBJECT_TYPE__ = $objectType;
            $object = $this->preProcessor->preProcess($object, $connection, $updateEntities, $insertEntities);

            if (null === $object) {
                continue;
            }

            $objects[] = $object;

            // Gotta break this up a little to prevent 10000000s of records from being saved at once
            if (count($objects) === $this->batchSize) {
                $this->logger->debug(
                    'Saving {count} {type} records for connection "{conn}"',
                    [
                        'count' => count($objects),
                        'type'  => $objectType,
                        'conn'  => $connection->getName(),
                    ]
                );
                $this->connector->enable();
                $this->connector->receive(
                    $objects,
                    SalesforceConsumerInterface::UPDATED,
                    $connection->getName(),
                    $updateEntities
                );
                $this->connector->disable();
                $objects = [];
            }
            ++$count;
        }

        if (!empty($objects)) {
            $this->logger->debug(
                'Saving {count} {type} records for connection "{conn}"',
                [
                    'count' => count($objects),
                    'type'  => $objectType,
                    'conn'  => $connection->getName(),
                ]
            );
            $this->connector->enable();
            $this->connector->receive(
                $objects,
                SalesforceConsumerInterface::UPDATED,
                $connection->getName(),
                $updateEntities
            );
            $this->connector->disable();
        }

        $this->logger->debug('Processed {count} {type} objects.', ['count' => $count, 'type' => $objectType]);
    }
}
