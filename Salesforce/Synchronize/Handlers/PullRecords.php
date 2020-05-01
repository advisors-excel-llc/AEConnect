<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\Client;
use AE\SalesforceRestSdk\Bulk\JobInfo;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;

class PullRecords implements SyncTargetHandler
{
    /**
     * a list of target names for which queries have ran.
     * E.G., [Product2 => true] indicates that Product2 has ran and has returned all of its results.
     *       [Account => false] indicates that an Account query has ran but has not yet returned all of its results.
     *       Absent key means there has never been a query which has ran.
     *
     * @var array
     */
    private $done = [];
    private $results = [];
    private $query;

    private $job;
    private $batchId;
    private $batchResults;
    /** @var \Generator */
    private $csv;

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(SyncTargetEvent $event): void
    {
        if ($event->getTarget()->count > $event->getConnection()->getBulkApiMinCount()) {
            //Apply our OFFSET by just skipping records
            do {
                $records = $this->bulk($event);
                if ($event->getTarget()->bulkOffset) {
                    $event->getTarget()->bulkOffset -= count($records);
                }
                if ($event->getTarget()->bulkOffset < 0) {
                    $records = array_slice($records, 0, count($records) + $event->getTarget()->bulkOffset);
                    $event->getTarget()->bulkOffset = 0;
                }
            } while ($event->getTarget()->bulkOffset);
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
     * Finally, we will splice the current result set we are working with.
     *
     * @return array
     *
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
     *
     * @return array
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function bulk(SyncTargetEvent $event)
    {
        $client = $event->getConnection()->getBulkClient();

        if (
            empty($this->batchResults) && (null === $this->csv || null === $this->csv->current()) &&
            (!isset($this->done[$event->getTarget()->name]) || !$this->done[$event->getTarget()->name])
        ) {
            $this->job = $client->createJob($event->getTarget()->name, JobInfo::QUERY, JobInfo::TYPE_CSV);
            $batch = $client->addBatch($this->job, $event->getTarget()->query);
            $this->batchId = $batch->getId();
            $this->batchResults = $this->getBatchResults($client, $this->job, $batch);
            $this->done[$event->getTarget()->name] = true;
        }

        if (!empty($this->batchResults) && (null === $this->csv || null === $this->csv->current())) {
            $nextBatch = array_shift($this->batchResults);
            $result = $client->getResult($this->job, $this->batchId, $nextBatch);
            $this->csv = $result->getContents(true);
        }

        if (null === $this->csv->current()) {
            //all done!
            return [];
        }

        $i = 0;
        $results = [];
        do {
            $row = $this->csv->current();
            $object = new CompositeSObject($event->getTarget()->name);
            foreach ($row as $field => $value) {
                $object->{$field} = $value;
            }
            $object->__SOBJECT_TYPE__ = $event->getTarget()->name;
            $results[] = $object;
            ++$i;
            $this->csv->next();
        } while (($this->csv->current() && $i < $event->getTarget()->batchSize));

        return $results;
    }

    /**
     * @return array
     *
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
