<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 10:29 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\SalesforceRestSdk\Model\Rest\Count;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class InboundBulkQueue
{
    use LoggerAwareTrait;

    /**
     * @var SObjectTreeMaker
     */
    private $treeMaker;

    /**
     * @var BulkApiProcessor
     */
    private $bulkApiProcessor;

    /**
     * @var CompositeApiProcessor
     */
    private $compositeApiProcessor;

    /**
     * @var BulkProgress
     */
    private $progress;

    /**
     * InboundBulkQueue constructor.
     *
     * @param SObjectTreeMaker $treeMaker
     * @param BulkApiProcessor $bulkApiProcessor
     * @param CompositeApiProcessor $compositeApiProcessor
     * @param BulkProgress $progress
     * @param null|LoggerInterface $logger
     */
    public function __construct(
        SObjectTreeMaker $treeMaker,
        BulkApiProcessor $bulkApiProcessor,
        CompositeApiProcessor $compositeApiProcessor,
        BulkProgress $progress,
        ?LoggerInterface $logger = null
    ) {
        $this->treeMaker             = $treeMaker;
        $this->progress              = $progress;
        $this->bulkApiProcessor      = $bulkApiProcessor;
        $this->compositeApiProcessor = $compositeApiProcessor;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param ConnectionInterface $connection
     * @param array $types
     * @param bool $updateEntities
     * @param bool $insertEntities
     *
     * @throws GuzzleException
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     */
    public function process(
        ConnectionInterface $connection,
        array $types = [],
        bool $updateEntities = false,
        bool $insertEntities = false
    ) {
        $map = $this->treeMaker->buildFlatMap($connection);

        if (!empty($types)) {
            $map = array_intersect($map, $types);
        }

        $counts = $connection->getRestClient()->count($map);

        $totals = [];

        foreach ($counts as $count) {
            $totals[$count->getName()] = $count->getCount();
        }

        $this->progress->setProgress([]);
        $this->progress->setTotals($totals);

        foreach ($counts as $count) {
            $this->startJob($connection, $count, $updateEntities, $insertEntities);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param Count $count
     * @param bool $updateEntities
     * @param bool $insertEntities
     */
    private function startJob(
        ConnectionInterface $connection,
        Count $count,
        bool $updateEntities,
        bool $insertEntities = false
    ) {
        $objectType       = $count->getName();
        $fields           = [];
        $recordTypes      = [];
        $metadataRegistry = $connection->getMetadataRegistry();
        $i                = 0;

        foreach ($metadataRegistry->findMetadataBySObjectType($objectType) as $metadata) {
            if (!$metadata->getDescribe()->isQueryable()) {
                $this->logger->debug('{obj} is not queryable', ['obj' => $objectType]);
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

            $this->logger->debug('QUERY {conn}: {query}', ['conn' => $connection->getName(), 'query' => $query]);
            $this->logger->debug('Record Count: {count}', ['count' => $count->getCount()]);
            $this->logger->debug('Bulk Min Count: {count}', ['count' => $connection->getBulkApiMinCount()]);

            if ($count->getCount() >= $connection->getBulkApiMinCount()) {
                $this->logger->debug('Processing Inbound using Bulk API');
                $this->bulkApiProcessor->process($connection, $objectType, $query, $updateEntities, $insertEntities);
            } else {
                $this->logger->debug('Processing Inbound using Composite API');
                $this->compositeApiProcessor->process(
                    $connection,
                    $objectType,
                    $query,
                    $updateEntities,
                    $insertEntities
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
        } catch (GuzzleException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
        }
    }
}
