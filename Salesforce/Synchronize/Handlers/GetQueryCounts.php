<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class GetQueryCounts implements SyncHandler
{
    public function process(SyncEvent $event): void
    {
        $unwantedQueryParts = ['ORDER BY', 'LIMIT', 'OFFSET'];
        $connection = $event->getConnection();
        $client = $connection->getRestClient()->getSObjectClient();
        foreach ($event->getConfig()->getQueries() as $target => $query) {
            $targetObj = new Target();
            $countQuery = 'SELECT COUNT(Id) '.substr($query, strpos($query, 'FROM'));
            //First of all lets check if we have LIMIT / OFFSET stated.
            $limit = PHP_INT_MAX;
            $offset = 0;
            //We'll record the limit and offset numbers here
            if (preg_match('/(?<=LIMIT )[\d]+/', $query, $matches)) {
                $limit = (int) $matches[0];
            }
            if (preg_match('/(?<=OFFSET )[\d]+/', $query, $matches)) {
                $offset = (int) $matches[0];
            }

            // We need to trim off any unwanted parts of the query, although we will know how many results we are going to
            // get if we already have limit + offset.
            foreach ($unwantedQueryParts as $part) {
                $countQuery = preg_replace('/'.$part.'[\s]+[^\s,]+(,[\s]*[\S]+)*/', '', $countQuery);
            }

            $response = $client->query($countQuery);
            $targetObj->name = $target;
            $targetObj->query = $query;
            $targetObj->count = min($response->getRecords()[0]->getExpr0() - $offset, $limit);

            // Bulk API does not support OFFSET so we have to add OFFSET to LIMIT, and then
            // strip OFFSET out from our query and instead skip that many records in the CSV we get back.
            if ($targetObj->count > $event->getConnection()->getBulkApiMinCount()) {
                // Add a bulk offset amount here so we know to SKIP that many records later on.
                $targetObj->bulkOffset = $offset;
                // remove OFFSET
                $targetObj->query = preg_replace('/OFFSET[\s]+[^\s,]+(,[\s]*[\S]+)*/', '', $targetObj->query);
                // Replace LIMIT with offset + limit
                $newLimit = $limit + $offset;
                $targetObj->query = preg_replace('/LIMIT[\s]+[^\s,]+(,[\s]*[\S]+)*/', "LIMIT $newLimit", $targetObj->query);
            }

            $event->addTarget($targetObj);
        }
    }
}
