<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:39 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\Client;
use AE\SalesforceRestSdk\Bulk\JobInfo;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use AE\SalesforceRestSdk\Psr7\CsvStream;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;

class BulkApiProcessor extends AbstractApiProcessor
{
    /**
     * @var BulkPreprocessor
     */
    private $preProcessor;

    public function __construct(
        BulkPreprocessor $preprocessor,
        SalesforceConnector $connector,
        BulkProgress $progress,
        int $batchSize = 50
    ) {
        parent::__construct($connector, $progress, $batchSize);
        $this->preProcessor = $preprocessor;
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
        string $query,
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

        $batchResults = $this->getBatchResults($client, $job, $batch);

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
        $count = 0;
        $objects = [];

        foreach ($result->getContents(true) as $row) {
            $object = new CompositeSObject($objectType);
            foreach ($row as $field => $value) {
                $object->{$field} = $value;
            }
            $object->__SOBJECT_TYPE__ = $objectType;
            $object                   = $this->preProcessor->preProcess(
                $object,
                $connection,
                $updateEntities,
                $insertEntities
            );

            if (null === $object) {
                $progress = $this->progress->getProgress($objectType) + 1;
                $this->progress->updateProgress($objectType, $progress);
                continue;
            }

            $objects[] = $object;

            // Gotta break this up a little to prevent 10000000s of records from being saved at once
            if (count($objects) === $this->batchSize) {
                $this->receiveObjects($objectType, $connection, $updateEntities, $objects);
                $objects = [];
            }
            ++$count;
        }

        if (!empty($objects)) {
            $this->receiveObjects($objectType, $connection, $updateEntities, $objects);
        }

        $this->logger->debug('Processed {count} {type} objects.', ['count' => $count, 'type' => $objectType]);
    }

    /**
     * @param Client $client
     * @param JobInfo $job
     * @param BatchInfo $batch
     *
     * @return array
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getBatchResults(Client $client, JobInfo $job, BatchInfo $batch)
    {
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

        return $batchResults;
}
}
