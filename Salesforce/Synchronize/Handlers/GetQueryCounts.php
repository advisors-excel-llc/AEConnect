<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class GetQueryCounts implements SyncHandler
{
    public function process(SyncEvent $event): void
    {
        $connection = $event->getConnection();
        $client = $connection->getRestClient()->getSObjectClient();
        foreach ($event->getConfig()->getQueries() as $target => $query) {
            $targetObj = new Target();
            $countQuery = 'SELECT COUNT(Id) ' . substr($query, strpos($query, 'FROM'));
            $response = $client->query($countQuery);
            $targetObj->name = $target;
            $targetObj->query = $query;
            $targetObj->count = $response->getRecords()[0]->getExpr0();
            $event->addTarget($targetObj);
        }
    }
}
