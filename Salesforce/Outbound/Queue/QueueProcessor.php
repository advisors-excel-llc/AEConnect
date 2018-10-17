<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 6:10 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionRequest;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use AE\SalesforceRestSdk\Rest\Composite\Builder\Reference;
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
    -- Attempt to append Independent SubRequets onto master requets that don't yet have 25 subrequests
 */

class QueueProcessor
{
    /**
     * @param array $inserts
     * @param array $updates
     * @param array $deletes
     *
     * @return ItemizedCollection
     */
    public static function buildQueue(
        ItemizedCollection $inserts,
        ItemizedCollection $updates,
        ItemizedCollection $deletes
    ): ItemizedCollection {
        $inserts = clone ($inserts);
        $updates = clone ($updates);
        $deletes = clone ($deletes);

        $partitions = $inserts->partition(
            function ($key, CompilerResult $element) {
                $object = $element->getSobject();
                foreach ($object->getFields() as $value) {
                    // If the object has a reference to another object, it's dependent
                    if ($value instanceof ReferencePlaceholder) {
                        return true;
                    }
                }

                return false;
            }
        );

        $dependent   = $partitions[0];
        $independent = $partitions[1];

        $queueGroups = self::buildQueueGroups(
            $independent,
            $dependent,
            $updates
        );

        $group = self::flattenQueueGroups($queueGroups);

        self::distribute($group, $independent, $updates, $deletes);

        return $group;
    }

    /**
     * @param ItemizedCollection $queueGroup
     *
     * @return CompositeRequestBuilder
     */
    public static function generateCompositeRequestBuilder(ItemizedCollection $queueGroup): CompositeRequestBuilder
    {
        $builder = new CompositeRequestBuilder();

        $queueGroup->forAll(
            function ($refId, ItemizedCollection $collection, $intent) use ($builder) {
                $records = [];
                /** @var CompilerResult $payload */
                foreach ($collection as $payload) {
                    $records[] = $payload->getSobject();
                }

                if (CompilerResult::INSERT === $intent) {
                    $builder->createSObjectCollection($refId, new CollectionRequest($records));
                } elseif (CompilerResult::UPDATE === $intent) {
                    $builder->updateSObjectCollection($refId, new CollectionRequest($records));
                } elseif (CompilerResult::DELETE === $intent) {
                    $builder->deleteSObjectCollection($refId, new CollectionRequest($records));
                }
            }
        );

        return $builder;
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

        while (!($subrequest = $collection->slice($offset, $length))->isEmpty()) {
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
        $types     = $subrequest->getKeys();

        if ($size < count($types)) {
            $types      = array_slice($types, $size);
            $newRequest = new ItemizedCollection();

            foreach ($types as $type) {
                $newRequest->set($type, $subrequest->get($type));
                $subrequest->remove($type);
            }

            $completed[] = $newRequest;
            $completed   = array_merge($completed, self::groupSubrequest($subrequest, $size));
        } else {
            $completed[] = $subrequest;
        }

        return $completed;
    }

    /**
     * @param ItemizedCollection $collection
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $updates
     *
     * @return ArrayCollection
     */
    private static function buildQueueGroups(
        ItemizedCollection $collection,
        ItemizedCollection $inserts,
        ItemizedCollection $updates
    ): ArrayCollection {
        $queueGroups = new ArrayCollection();
        $collection->sort();

        /** @var ItemizedCollection[] $groups */
        $groups = self::groupSubrequests(self::partitionSubrequests($collection));

        foreach ($groups as $group) {
            $queueGroup = new QueueGroup();
            $queueGroup->setItems($group);
            $queueGroups->add($queueGroup);

            self::buildQueueGroup($queueGroup, $group, $inserts, $updates);

            foreach ($group as $item) {
                $collection->removeElement($item);
            }
        }

        return $queueGroups;
    }

    private static function buildQueueGroup(
        QueueGroup $group,
        ItemizedCollection $collection,
        ItemizedCollection $inserts,
        ItemizedCollection $updates
    ) {
        $types = $collection->getKeys();
        foreach ($types as $type) {
            $subGroup = new QueueGroup();

            $refIds = $collection->getKeys($type);

            $subInserts = $inserts->filter(
                function (CompilerResult $result) use ($refIds) {
                    $object = $result->getSObject();
                    $objRefIds = [];

                    foreach ($object->getFields() as $value) {
                        if ($value instanceof ReferencePlaceholder) {
                            $objRefIds[] = $value->getEntityRefId();
                        }
                    }

                    return !empty($objRefIds) && count(array_intersect($objRefIds, $refIds)) === count($objRefIds);
                }
            );

            $subUpdates = $updates->filter(
                function (CompilerResult $result) use ($refIds) {
                    $object = $result->getSObject();
                    $objRefIds = [];

                    foreach ($object->getFields() as $value) {
                        if ($value instanceof ReferencePlaceholder) {
                            $objRefIds[] = $value->getEntityRefId();
                        }
                    }

                    return !empty($objRefIds) && count(array_intersect($objRefIds, $refIds)) === count($objRefIds);
                }
            );


            if (!$subInserts->isEmpty()) {
                $subGroup->setItems($subInserts);
            }

            if (!$subUpdates->isEmpty()) {
                $subGroup->setDependentUpdates($subUpdates);
            }

            if ($subGroup->count() > 0) {
                if (!$subInserts->isEmpty()) {
                    self::buildQueueGroup($subGroup, $subInserts, $inserts, $updates);
                }

                $group->addChildGroup($subGroup);

                foreach ($subInserts as $item) {
                    $inserts->removeElement($item);
                }

                foreach ($subUpdates as $item) {
                    $updates->removeElement($item);
                }
            }
        }
    }

    /**
     * @param ArrayCollection $queueGroups
     *
     * @return ItemizedCollection
     */
    private static function flattenQueueGroups(
        ArrayCollection $queueGroups
    ): ItemizedCollection {
        $group = new ItemizedCollection();

        /** @var QueueGroup $queueGroup */
        foreach ($queueGroups as $queueGroup) {
            self::flattenQueueGroup($group, $queueGroup);
        }

        return $group;
    }

    public static function flattenQueueGroup(
        ItemizedCollection $group,
        QueueGroup $queueGroup
    ) {
        $count = $group->count();
        $queueCount = $queueGroup->groupCount();
        $totalCount = $count + $queueCount;

        if ($totalCount <= 25) {
            $insertRefId = uniqid('insert_');
            $updateRefId = uniqid('update_');

            if (!$queueGroup->getItems()->isEmpty()) {
                $group->set($insertRefId, $queueGroup->getItems(), CompilerResult::INSERT);
                // Resolve the Reference Placeholders
                self::hydrateReferences($group, $queueGroup->getItems());
            }

            if (!$queueGroup->getDependentUpdates()->isEmpty()) {
                $group->set($updateRefId, $queueGroup->getDependentUpdates(), CompilerResult::UPDATE);
                // Resolve the Reference Placeholders
                self::hydrateReferences($group, $queueGroup->getDependentUpdates());
            }

            foreach ($queueGroup->getChildGroups() as $dependentGroup) {
                self::flattenQueueGroup($group, $dependentGroup);
            }

            return true;
        }

        return false;
    }

    /**
     * @param ItemizedCollection $group
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $updates
     * @param ItemizedCollection $deletes
     */
    private static function distribute(
        ItemizedCollection $group,
        ItemizedCollection $inserts,
        ItemizedCollection $updates,
        ItemizedCollection $deletes
    ) {
        // Try and get the composite request to 25 sub-requests
        self::appendInsertSubRequests($group, $inserts);
        self::appendCollection($group, $updates);
        self::appendCollection($group, $deletes, CompilerResult::DELETE);

        // Add any remaining records to any sub-requests that have room for more
        /**
         * @var string $item
         * @var ItemizedCollection $subset
         */
        foreach ($group as $item => $subset) {
            switch ($item) {
                case CompilerResult::INSERT:
                    self::distributeInserts($inserts, $subset);
                    break;
                case CompilerResult::UPDATE:
                    self::distributeCollection($updates, $subset, CompilerResult::UPDATE);
                    break;
                case CompilerResult::DELETE:
                    self::distributeCollection($deletes, $subset, CompilerResult::DELETE);
                    break;
            }
        }
    }

    /**
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $subset
     */
    private static function spreadInsertsOverLikeTypes(
        ItemizedCollection $inserts,
        ItemizedCollection $subset
    ): void {
        $diff  = 200 - count($subset);
        $types = $subset->getKeys();
        while ($diff > 0 && ($type = current($types))) {
            $typeSet = new ItemizedCollection($inserts->splice(0, $diff, $type));

            if ($typeSet->isEmpty()) {
                continue;
            }

            $diff -= count($typeSet);

            foreach ($typeSet as $type => $set) {
                foreach ($set as $item) {
                    $subset->add($item, $type);
                    $inserts->removeElement($item);
                }
            }
        }
    }

    /**
     * @param ItemizedCollection $inserts
     * @param ItemizedCollection $subset
     */
    private static function appendInsertsToExistingSubRequest(
        ItemizedCollection $inserts,
        ItemizedCollection $subset
    ) {
        $diff     = 200 - count($subset);
        $typeDiff = 5 - count($subset->getKeys());
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
    private static function distributeInserts(
        ItemizedCollection $inserts,
        ItemizedCollection $group
    ) {
        self::spreadInsertsOverLikeTypes($inserts, $group);
        self::appendInsertsToExistingSubRequest($inserts, $group);

        $diff = 25 - count($group->getKeys());
        $sets = self::groupSubrequests(self::partitionSubrequests($inserts));

        while ($diff > 0 && ($set = current($sets))) {
            $insertRefId = uniqid('insert_');
            $group->set($insertRefId, $set, CompilerResult::INSERT);

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
     * @param string $grouping
     */
    private static function distributeCollection(
        ItemizedCollection $collection,
        ItemizedCollection $group,
        string $grouping = CompilerResult::UPDATE
    ) {
        foreach ($group as $type => $set) {
            $diff  = 200 - count($set);
            $items = $collection->get($type);
            while ($diff > 0 && ($item = current($items))) {
                $key = uniqid(strtolower($grouping).'_');
                $group->set($key, $item, $type);
                $diff -= 1;
                next($items);
            }
        }
    }

    /**
     * @param ItemizedCollection $group
     * @param ItemizedCollection $inserts
     */
    private static function appendInsertSubRequests(
        ItemizedCollection $group,
        ItemizedCollection $inserts
    ): void {
        $diff = 25 - $group->count();
        $partitions = self::groupSubrequests(self::partitionSubrequests($inserts));
        while ($diff > 0 && ($partition = current($partitions))) {
            $insertRefId = uniqid('insert_');
            $group->set($insertRefId, $partition, CompilerResult::INSERT);

            $diff -= 1;
            next($partitions);
        }
    }

    /**
     * @param ItemizedCollection $group
     * @param ItemizedCollection $collection
     * @param string $grouping
     */
    private static function appendCollection(
        ItemizedCollection $group,
        ItemizedCollection $collection,
        string $grouping = CompilerResult::UPDATE
    ): void {
        $diff = 25 - $group->count();
        $types = $collection->getKeys();

        while ($diff > 0 && ($type = current($types))) {
            /** @var ItemizedCollection[] $partitions */
            $partitions = self::partitionSubrequests(new ItemizedCollection([$type => $collection->get($type)]));
            while ($diff > 0 && ($partition = current($partitions))) {
                $key = uniqid(strtolower($grouping).'_');
                $group->set($key, $partition, $grouping);

                $diff -= 1;
                next($partitions);
            }
            next($types);
        }
    }

    /**
     * @param ItemizedCollection $group
     * @param ItemizedCollection|CompilerResult[] $payloads
     */
    private static function hydrateReferences(
        ItemizedCollection $group,
        ItemizedCollection $payloads
    ): void {
        /** @var CompilerResult $payload */
        foreach ($payloads as $payload) {
            $object = $payload->getSobject();
            foreach ($object->getFields() as $field => $value) {
                if ($value instanceof ReferencePlaceholder) {
                    $eRefId = $value->getEntityRefId();
                    /**
                     * @var string $refId
                     * @var ItemizedCollection $collection
                     */
                    foreach ($group->get(CompilerResult::INSERT) as $refId => $collection) {
                        $items = $collection->toArray();
                        $index = array_search($eRefId, array_keys($items), true);
                        if (false !== $index) {
                            $value->setReference(
                                new Reference($refId)
                            );
                            $value->setField('records['.$index.'].id');
                            $object->$field = (string)$value;
                        }
                    }
                }
            }
        }
    }
}