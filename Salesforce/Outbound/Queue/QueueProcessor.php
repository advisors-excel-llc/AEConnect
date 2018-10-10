<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 6:10 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Salesforce\Outbound\MessagePayload;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionRequest;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeRequest;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use Doctrine\Common\Collections\ArrayCollection;

/*
 * Model structure and Algorithm rules
 *
 * + Independent Inserts
        + Dependent Inserts
            + Batch SubRequest
                - up to 200 records of no more than 5 different SObject Types
        + Dependent Updates
            + SubRequest for SObject Type X
                - up to 200 records of SObject Type X
            + SubRequest for SObject Type Y
                - up to 200 records of SObject Type Y
    + Independent Updates
        + SubRequest for SObject Type X
            - up to 200 records of SObject Type X
        + SubRequest for SObject Type Y
            - up to 200 records of SObject Type Y
    + Independent Deletes
        + SubRequest for SObject Type X
            - up to 200 records of SObject Type X
        + SubRequest for SObject Type Y
            - up to 200 records of SObject Type Y

    RULES
    -- No more than 25 total subrequests per master request
    -- Attempt to destribute Independent Update SubRequest records across Dependent Update SubRequests of the same type that don't have 200 records in them yet
    -- Attempt to append Independent Delete SubRequets onto master requets that don't yet have 25 subrequests
 */

class QueueProcessor
{
    /**
     * @param ArrayCollection $messages
     *
     * @return array
     */
    public static function buildQueue(ArrayCollection $messages): array
    {
        $inserts = new ItemizedCollection($messages->get(SalesforceConnector::INTENT_INSERT) ?: []);
        $updates = new ItemizedCollection($messages->get(SalesforceConnector::INTENT_UPDATE) ?: []);
        $deletes = new ItemizedCollection($messages->get(SalesforceConnector::INTENT_DELETE) ?: []);

        $independent = new ItemizedCollection();
        $dependent   = new ItemizedCollection();

        /**
         * @var string $refId
         * @var MessagePayload $payload
         */
        foreach ($inserts as $type => $message) {
            foreach ($message as $refId => $payload) {
                $object = $payload->getSobject();
                foreach ($object->getFields() as $value) {
                    // If the object has a reference to another object, it's dependent
                    if ($value instanceof ReferencePlaceholder) {
                        $dependent->set($refId, $object, $type);
                        break;
                    }
                }
                // If the object doesn't have a reference, it's independent
                $independent->set($refId, $object, $type);
            }
        }

        $queueGroups = self::buildQueueGroups(
            $independent,
            $dependent,
            $updates
        );

        $groups = self::flattenQueueGroups($queueGroups);

        self::distribute($groups, $independent, $updates, $deletes);

        return $groups;
    }

    /**
     * @param array|ItemizedCollection[] $groups
     *
     * @return array|CompositeRequest[]
     */
    public static function buildCompositeRequests(array $groups): array
    {
        $builders = self::generateCompositeRequestBuilders($groups);
        $requests = [];

        /** @var CompositeRequestBuilder $builder */
        foreach ($builders as $builder) {
            $requests[] = $builder->build();
        }

        return $requests;
    }

    /**
     * @param array|ItemizedCollection[] $groups
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $updates
     * @param ItemizedCollection $deletes
     */
    private static function distribute(
        array &$groups,
        ItemizedCollection $inserts,
        ItemizedCollection $updates,
        ItemizedCollection $deletes
    ) {
        foreach ($groups as $group) {
            /**
             * @var string $item
             * @var ItemizedCollection $subset
             */
            foreach ($group as $item => $subset) {
                switch ($item) {
                    case SalesforceConnector::INTENT_INSERT:
                        self::distributeInserts($inserts, $subset);
                        break;
                    case SalesforceConnector::INTENT_UPDATE:
                        self::distributeCollection($updates, $subset);
                        break;
                    case SalesforceConnector::INTENT_DELETE:
                        self::distributeCollection($deletes, $subset);
                        break;
                }
            }

            // TODO: Process leftovers
        }
    }

    /**
     * @param ArrayCollection $queueGroups
     *
     * @return array|ItemizedCollection[]
     */
    private static function flattenQueueGroups(ArrayCollection $queueGroups): array
    {
        $groups = [new ItemizedCollection()];

        /** @var QueueGroup $queueGroup */
        foreach ($queueGroups as $queueGroup) {
            /** @var ItemizedCollection $group */
            $group = end($groups);

            if (!self::flattenQueueGroup($group, $queueGroup)) {
                $groups[] = $group = new ItemizedCollection();
                self::flattenQueueGroup($group, $queueGroup);
            }
        }

        return $groups;
    }

    public static function flattenQueueGroup(ItemizedCollection $group, QueueGroup $queueGroup)
    {
        if ($group->count() + $queueGroup->count() <= 25) {
            $group->add($queueGroup->getItems(), SalesforceConnector::INTENT_INSERT);
            $group->add($queueGroup->getDependentUpdates(), SalesforceConnector::INTENT_UPDATE);

            foreach ($queueGroup->getDependentGroups() as $dependentGroup) {
                self::flattenQueueGroup($group, $dependentGroup);
            }

            return true;
        }

        return false;
    }

    /**
     * @param array|CompositeRequestBuilder[] $groups
     *
     * @return array
     */
    private static function generateCompositeRequestBuilders(array $groups): array
    {
        $builders = [];

        /** @var ItemizedCollection $queueGroup */
        foreach ($groups as $queueGroup) {
            $builder = new CompositeRequestBuilder();
            foreach ($queueGroup as $intent => $group) {
                /**
                 * @var string $refId
                 * @var ItemizedCollection $set
                 */
                foreach ($group as $refId => $set) {
                    if (SalesforceConnector::INTENT_INSERT === $intent) {
                        $builder->createSObjectCollection($refId, new CollectionRequest($set->toArray()));
                    } elseif (SalesforceConnector::INTENT_UPDATE === $intent) {
                        $builder->updateSObjectCollection($refId, new CollectionRequest($set->toArray()));
                    } elseif (SalesforceConnector::INTENT_DELETE === $intent) {
                        $builder->deleteSObjectCollection($refId, new CollectionRequest($set->toArray()));
                    }
                }
            }
            $builders[] = $builder;
        }

        return $builders;
    }

    /**
     * Ensure each subrequest has no more than 200 records
     *
     * @param ItemizedCollection $collection
     * @param int $length
     *
     * @return array
     */
    private static function partitionSubrequests(ItemizedCollection $collection, int $length = 200): array
    {
        $subrequests = [];
        $offset      = 0;

        while (!($subrequest = $collection->splice($offset, $length))->isEmpty()) {
            $subrequests[] = $subrequest;
            $offset        += $length;
        }

        return $subrequests;
    }

    /**
     * @param array|ItemizedCollection[] $subrequests
     * @param int $size
     *
     * @return array
     */
    private static function groupSubrequests(array $subrequests, $size = 5): array
    {
        $completed = [];
        /**
         * @var ItemizedCollection $subrequest
         */
        foreach ($subrequests as $subrequest) {
            $completed = array_merge($completed, self::groupSubrequest($subrequest, $size));
        }

        return $completed;
    }

    /**
     * @param ItemizedCollection $subrequest
     * @param int $size
     *
     * @return array
     */
    private static function groupSubrequest(ItemizedCollection $subrequest, $size = 5): array
    {
        $completed = [];

        $completed[] = $subrequest;
        $types       = $subrequest->distinctItems();
        if ($size < count($types)) {
            $types      = array_slice($types, 5);
            $newRequest = new ItemizedCollection();

            foreach ($types as $type) {
                $newRequest->set($type, $subrequest->get($type));
                $subrequest->remove($type);
            }

            $completed = array_merge($completed, self::groupSubrequest($newRequest, $size));
        }

        return $completed;
    }

    private static function buildQueueGroups(
        ItemizedCollection $collection,
        ItemizedCollection $inserts,
        ItemizedCollection $updates
    ): ArrayCollection {
        $queueGroups = new ArrayCollection();
        $collection->sort();

        /** @var ItemizedCollection[] $groups */
        $groups = self::groupSubrequests(self::partitionSubrequests($collection));
        foreach ($groups as $set) {
            foreach ($set->getKeys() as $group) {
                $refIds     = $set->getKeys($group);
                $queueGroup = new QueueGroup();

                $insert = $inserts->filter(
                    function ($value) use ($refIds) {
                        /** @var MessagePayload $value */

                        foreach ($value->getSobject()->getFields() as $field) {
                            if ($field instanceof ReferencePlaceholder) {
                                if (in_array($field->getEntityRefId(), $refIds)) {
                                    return true;
                                }
                            }
                        }

                        return false;
                    }
                );

                // Remove matched dependent inserts from the root collection to speed up future searches
                foreach ($insert as $remove) {
                    $inserts->removeElement($remove);
                }

                $dependentUpdates = $updates->filter(
                    function ($value) use ($refIds) {
                        /** @var MessagePayload $value */

                        foreach ($value->getSobject()->getFields() as $field) {
                            if ($field instanceof ReferencePlaceholder) {
                                if (in_array($field->getEntityRefId(), $refIds)) {
                                    return true;
                                }
                            }
                        }

                        return false;
                    }
                );

                /** @var MessagePayload $payload */
                foreach ($dependentUpdates as $payload) {
                    // remove the payload from the update group to allow for faster searching
                    $updates->removeElement($payload);
                }

                $queueGroup->setItems($insert);
                $queueGroup->setDependentUpdates($dependentUpdates);

                // Process and normalize sub-requests to ensure limits are met
                $queueGroup->setDependentGroups(
                    self::buildQueueGroups($insert, $inserts, $updates)
                );

                $queueGroups->add($queueGroup);
            }
        }

        return $queueGroups;
    }

    /**
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $subrequest
     */
    private static function spreadInsertsOverLikeTypes(
        ItemizedCollection $inserts,
        ItemizedCollection $subrequest
    ): void {
        $diff  = 200 - count($subrequest);
        $types = $subrequest->distinctItems();
        while ($diff > 0 && ($type = current($types))) {
            if (!$subrequest->containsKey($type)) {
                continue;
            }

            $typeSet = new ItemizedCollection($subrequest->slice(0, $diff, $type));
            $diff    -= count($typeSet);

            foreach ($typeSet as $type => $set) {
                foreach ($set as $item) {
                    $subrequest->add($item, $type);
                    $inserts->removeElement($item);
                }
            }
        }
    }

    /**
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $subset
     *
     * @return mixed
     */
    private static function appendInsertsToExistingSubRequest(ItemizedCollection $inserts, ItemizedCollection $subset)
    {
        $diff     = 200 - count($subset);
        $typeDiff = 5 - count($subset->distinctItems());
        $types    = $inserts->getKeys();
        while ($diff > 0 && $typeDiff > 5 && ($type = current($types))) {
            $set = new ItemizedCollection($subset->slice(0, $diff, $type));

            if ($set->isEmpty()) {
                continue;
            }

            $diff     -= count($set);
            $typeDiff -= 1;

            foreach ($set->toArray() as $item) {
                $subset->add($item, $type);
                $inserts->removeElement($item);
            }

            next($types);
        }
    }

    /**
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $group
     */
    private static function distributeInserts(ItemizedCollection $inserts, ItemizedCollection $group)
    {
        self::spreadInsertsOverLikeTypes($inserts, $group);
        self::appendInsertsToExistingSubRequest($inserts, $group);

        $diff = 25 - count($group);

        $sets = self::groupSubrequests(self::partitionSubrequests($inserts));
        while ($diff > 0 && ($set = current($sets))) {
            $group->add($set, SalesforceConnector::INTENT_INSERT);

            foreach ($set as $item) {
                $inserts->removeElement($item);
            }

            $diff -= 1;
            next($sets);
        }
    }

    /**
     * @param ItemizedCollection $collection
     * @param ItemizedCollection $group
     */
    private static function distributeCollection(ItemizedCollection $collection, ItemizedCollection $group)
    {
        foreach ($group as $type => $set) {
            $diff  = 200 - count($set);
            $items = $collection->get($type);
            while ($diff > 0 && ($item = current($items))) {
                $group->add($item, $type);
                $collection->removeElement($item);
                $diff -= 1;
                next($items);
            }
        }

        $diff  = 25 - count($group);
        $types = $collection->getKeys();

        while ($diff > 0 && ($type = current($types))) {
            $partition = $collection->slice(0, 200, $type);
            $group->add($partition, $type);

            foreach ($partition as $item) {
                $collection->removeElement($item);
            }

            $diff -= 1;
            next($types);
        }
    }
}
