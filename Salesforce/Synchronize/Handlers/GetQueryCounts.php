<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class GetQueryCounts implements SyncHandler
{
    public function process(SyncEvent $event): void
    {
        $unwantedQueryParts = [' ORDER BY '];
        $connection = $event->getConnection();
        $client = $connection->getRestClient()->getSObjectClient();
        foreach ($event->getConfig()->getQueries() as $target => $query) {
            $targetObj = new Target();
            $countQuery = 'SELECT COUNT(Id) ' . substr($query, strpos($query, 'FROM'));

            // We need to trim off any unwanted parts of the query, although we will know how many results we are going to
            // get if we already have limit + offset.
            foreach ($unwantedQueryParts as $part) {
                $index = strpos($countQuery, $part);
                if ($index) {
                    $countQuery = substr($countQuery, 0, $index);
                }
            }

            $response = $client->query($countQuery);
            $targetObj->name = $target;
            $targetObj->query = $query;
            $targetObj->count = $response->getRecords()[0]->getExpr0();
            $event->addTarget($targetObj);
        }
    }


}
