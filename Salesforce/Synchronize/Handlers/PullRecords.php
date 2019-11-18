<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\Client;
use AE\SalesforceRestSdk\Bulk\JobInfo;

class PullRecords implements SyncTargetHandler
{
    /**
     * a list of target names for which queries have ran.
     * E.G., [Product2 => true] indicates that Product2 has ran and has returned all of its results.
     *       [Account => false] indicates that an Account query has ran but has not yet returned all of its results.
     *       Absent key means there has never been a query which has ran.
     * @var array
     */
    private $done = [];
    private $results = [];
    private $query;


    /**
     * @param SyncTargetEvent $event
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(SyncTargetEvent $event): void
    {
        if ($event->getTarget()->count > $event->getConnection()->getBulkApiMinCount())
        {
            $records = $this->bulk($event);
        } else {
            $records = $this->composite($event);
        }
        $event->getTarget()->records = [];
        foreach ($records as $record) {
            $event->getTarget()->records[] = new Record($record);
        }
    }

    /**
     * In composite, we want to first add a initial query as a string to the object if there isn't one already.
     * If there is a query but we have no results to splice off the array, we will get the next query results from the client
     * and put those results on the object.
     * Finally, we will splice the current result set we are working with
     * @param SyncTargetEvent $event
     * @return array
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function composite(SyncTargetEvent $event)
    {
        $client = $event->getConnection()->getRestClient()->getSObjectClient();
        if (!$this->query) {
            $this->query = $event->getTarget()->query;
        }

        if (
            empty($this->results) &&
            (!isset($this->done[$event->getTarget()->name]) || !$this->done[$event->getTarget()->name])
        ) {
            // The client query will either take a Query object or a query as a string.
            $this->query = $client->query($this->query);
            $this->results = $this->query->getRecords();
            $this->done[$event->getTarget()->name] = $this->query->isDone();
        }

        if (empty($this->results) && $this->done[$event->getTarget()->name]) {
            //If we are out of results and we are done with this query completely, free this object from memory so the next
            //incoming query can run.
            $this->query = null;
            return [];
        }

        return array_splice($this->results, 0, $event->getTarget()->batchSize);
    }

    /**
     * In Bulk, we want to first run the job for getting a .csv dump of all the results from salesforce into our results set on the object
     * and then as long as we have results, we will splice off BATchSize and supply that to the event every time we pull more records.
     * @param SyncTargetEvent $event
     * @return array
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function bulk(SyncTargetEvent $event)
    {
        if (
            empty($this->results) &&
            (!isset($this->done[$event->getTarget()->name]) || !$this->done[$event->getTarget()->name])
        ) {
            $client        = $event->getConnection()->getBulkClient();
            $job           = $client->createJob($event->getTarget()->name, JobInfo::QUERY, JobInfo::TYPE_CSV);
            $batch         = $client->addBatch($job, $event->getTarget()->query);
            $this->results = $this->getBatchResults($client, $job, $batch);
            $this->done[$event->getTarget()->name] = true;
        }
        return array_splice($this->results, 0, $event->getTarget()->batchSize);
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
    private function getBatchResults(Client $client, JobInfo $job, BatchInfo $batch)
    {
        do {
            $batchStatus = $client->getBatchStatus($job, $batch->getId());
            if (BatchInfo::STATE_COMPLETED !== $batchStatus->getState()) {
                sleep(10);
            }
        } while (BatchInfo::STATE_COMPLETED !== $batchStatus->getState());

        $batchResults = $client->getBatchResults($job, $batch->getId());

        return $batchResults;
    }
}
