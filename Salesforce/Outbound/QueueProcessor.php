<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 6:10 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound;

use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use Ramsey\Uuid\Uuid;

class QueueProcessor
{
    private $builders = [];

    /**
     * @param array $messages
     *
     * @return array
     */
    public function buildQueue(array $messages): array
    {
        $this->builders[] = $builder = new CompositeRequestBuilder();

        if (array_key_exists(SalesforceConnector::INTENT_INSERT, $messages)) {
            $this->processInserts($messages[SalesforceConnector::INTENT_INSERT], $builder);
        }

        $requests = [];

        foreach ($this->builders as $builder) {
            $requests[] = $builder->build();
        }

        return $requests;
    }

    /**
     * @param array $messages
     *
     * The rules to a composite insert are:
     *    1. No more than 200 records per request
     *    2. Inserts are batched out based on object type (so group like objects together)
     *    3. No more than 5 batches of object types per request
     *    4. Max 25 total requests within 1 Composite request
     */
    private function processInserts(array $messages, CompositeRequestBuilder $builder)
    {
        $batch          = 1;
        $currentBatchId = Uuid::uuid4()->toString();
        $count          = 0;
        $objects        = new ItemizedCollection();
        foreach ($messages as $refId => $payload) {

            ++$count;

            if ($count === 200) {
                $currentBatchId = Uuid::uuid4()->toString();
            }
        }
    }

    /**
     * @param array|MessagePayload $messages
     */
    private function buildInsertBatches(array $messages)
    {
        $batches     = new ItemizedCollection();
        $independent = new ItemizedCollection();
        $dependent   = new ItemizedCollection();

        /**
         * @var string $refId
         * @var MessagePayload $payload
         */
        foreach ($messages as $refId => $payload) {
            $object = $payload->getSobject();
            foreach ($object->getFields() as $value) {
                // If the object has a reference to another object, it's dependent
                if ($value instanceof ReferencePlaceholder) {
                    $dependent->set($refId, $object, $payload->getMetadata()->getSObjectType());
                    break;
                }
            }
            // If the object doesn't have a reference, it's independent
            $independent->set($refId, $object, $payload->getMetadata()->getSObjectType());
        }

        $independent->sort();
        $dependent->sort();

        $indBatchNo = $this->numOfBatches($independent);
        $depBatchNo = $this->numOfBatches($dependent);
    }

    private function numOfBatches(ItemizedCollection $collection): int
    {
        $keys = $collection->getKeys();
        sort($keys, SORT_ASC);
        $count = 0;
        $lastKey = null;

        foreach ($keys as $key) {
            if ($lastKey === $key) {
                continue;
            }

            $lastKey = $key;
            ++$count;
        }

        return $count;
    }
}
