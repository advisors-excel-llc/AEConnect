<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 5:00 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;

class InboundQueryProcessor
{
    /**
     * @var BulkApiProcessor
     */
    private $bulkApiProcessor;

    /**
     * @var CompositeApiProcessor
     */
    private $compositeApiProcessor;

    public function __construct(BulkApiProcessor $bulkApiProcessor, CompositeApiProcessor $compositeApiProcessor)
    {
        $this->bulkApiProcessor      = $bulkApiProcessor;
        $this->compositeApiProcessor = $compositeApiProcessor;
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $query
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(ConnectionInterface $connection, string $query)
    {
        $matches = [];

        if (preg_match(
            '/^SELECT\s+(?P<fields>.+?)\s+FROM\s+(?P<objectType>[^\s]+)(\s+(?P<where>.+))?$/i',
            $query,
            $matches
        )) {
            $fields      = [];
            $objectType  = $matches['objectType'];
            $where       = $matches['where'];
            $metadata    = $connection->getMetadataRegistry()->findMetadataBySObjectType($objectType);
            $queryFields = array_map('trim', explode(',', $matches['fields']));
            $wildCard    = in_array('*', $queryFields);

            foreach ($metadata as $metadatum) {
                if ($wildCard) {
                    $fields = array_merge($fields, array_values($metadatum->getPropertyMap()));
                } else {
                    foreach ($queryFields as $field) {
                        if (null !== $metadatum->getMetadataForField($field)) {
                            $fields[] = $field;
                        }
                    }
                }
            }

            if (empty($fields)) {
                throw new \RuntimeException("No fields provided in the query were mapped to any local entities.");
            }

            $sObjectClient = $connection->getRestClient()->getSObjectClient();
            $countSOQL     = "SELECT Count(Id) total FROM $objectType $where";
            $countQuery    = $sObjectClient->query($countSOQL);

            if ($countQuery->getTotalSize() === 0) {
                throw new \RuntimeException("Unable to get a record count for the query: $countSOQL");
            }

            $records    = $countQuery->getRecords();
            $updateSOQL = "SELECT ".implode(',', $fields)." FROM $objectType $where";
            $total      = $records[0]->total;

            if ($total == 0) {
                throw new \RuntimeException("No results returned for the given query");
            }

            if ($total >= $connection->getBulkApiMinCount()) {
                $this->bulkApiProcessor->process($connection, $objectType, true, $updateSOQL);
            } else {
                $this->compositeApiProcessor->process($connection, $objectType, true, $updateSOQL);
            }
        }
    }
}
