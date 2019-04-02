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

    public function __construct(BulkPreprocessor $preprocessor, SalesforceConnector $connector)
    {
        $this->preProcessor = $preprocessor;
        $this->connector    = $connector;
        $this->logger       = new NullLogger();
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
    public function process(
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
                $this->save($result, $objectType, $connection, $updateEntities);
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
     *
     * @throws MappingException
     * @throws ORMException
     */
    public function save(
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

            if (!$updateEntities) {
                $object = $this->preProcessor->preProcess($object, $connection);
            }

            $objects[] = $object;

            // Gotta break this up a little to prevent 10000000s of records from being saved at once
            if (count($objects) === 200) {
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
