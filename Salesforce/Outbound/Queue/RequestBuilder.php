<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/16/18
 * Time: 11:31 AM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionRequest;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeRequest;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use AE\SalesforceRestSdk\Rest\Composite\Builder\Reference;
use Doctrine\Common\Collections\ArrayCollection;

class RequestBuilder
{
    /**
     * @param $items
     *
     * @return array
     */
    public static function build($items): array
    {
        $tree = DependencyTreeBuilder::build($items);

        $collection = new ArrayCollection(
            [
                CompilerResult::INSERT => new ItemizedCollection(),
                CompilerResult::UPDATE => new ItemizedCollection(),
                CompilerResult::DELETE => new ItemizedCollection(),
            ]
        );

        foreach ($tree as $node) {
            self::addToCollection($node, $collection);
        }

        $inserts = self::partitionInserts($collection->get(CompilerResult::INSERT));
        $updates = self::partitionCollection($collection->get(CompilerResult::UPDATE));
        $deletes = self::partitionCollection($collection->get(CompilerResult::DELETE));

        self::hydrateReferencePlaceholders($inserts, $inserts);
        foreach ($updates as $items) {
            self::hydrateReferencePlaceholders($items, $inserts);
        }

        return [
            CompilerResult::INSERT => $inserts,
            CompilerResult::UPDATE => $updates,
            CompilerResult::DELETE => $deletes,
        ];
    }

    /**
     * @param array $inserts
     * @param array $updates
     * @param array $deletes
     *
     * @return CompositeRequest
     */
    public static function buildRequest(array $inserts = [], array $updates = [], array $deletes = []): CompositeRequest
    {
        $builder = new CompositeRequestBuilder();

        foreach ($inserts as $ref => $items) {
            $subrequest = new CollectionRequest();
            /** @var CompilerResult $item */
            foreach ($items as $item) {
                $subrequest->addRecord($item->getSObject());
            }

            if (!$subrequest->getRecords()->isEmpty()) {
                $builder->createSObjectCollection($ref, $subrequest);
            }
        }

        foreach ($updates as $ref => $items) {
            $subrequest = new CollectionRequest();
            /** @var CompilerResult $item */
            foreach ($items as $item) {
                $subrequest->addRecord($item->getSObject());
            }

            if (!$subrequest->getRecords()->isEmpty()) {
                $builder->updateSObjectCollection($ref, $subrequest);
            }
        }

        foreach ($deletes as $ref => $items) {
            $subrequest = new CollectionRequest();
            /** @var CompilerResult $item */
            foreach ($items as $item) {
                $subrequest->addRecord($item->getSObject());
            }

            if (!$subrequest->getRecords()->isEmpty()) {
                $builder->deleteSObjectCollection($ref, $subrequest);
            }
        }

        return $builder->build();
    }

    /**
     * @param DependencyNode $node
     * @param ArrayCollection $collection
     */
    private static function addToCollection(DependencyNode $node, ArrayCollection $collection)
    {
        $item   = $node->getItem();
        $type   = $item->getMetadata()->getSObjectType();
        $intent = $item->getIntent();
        $refId  = $item->getReferenceId();

        /** @var ItemizedCollection $subCollection */
        $subCollection = $collection->get($intent);
        $subCollection->set($refId, $item, $type);

        if (!$node->getDependencies()->isEmpty()) {
            foreach ($node->getDependencies() as $dependency) {
                if (self::canAddToCollection($dependency, $collection)) {
                    self::addToCollection($dependency, $collection);
                }
            }
        }
    }

    /**
     * @param DependencyNode $node
     * @param ArrayCollection $collection
     *
     * @return bool
     */
    private static function canAddToCollection(DependencyNode $node, ArrayCollection $collection): bool
    {
        $insertCount  = 0;
        $updateCounts = [];
        $deleteCounts = [];
        $insertTypes  = [];

        self::countSubRequests($node, $insertCount, $updateCounts, $deleteCounts, $insertTypes);

        $collectionInsertCount  = ceil($collection->get(CompilerResult::INSERT)->count() % 200);
        $collectionUpdateCounts = [];
        $collectionDeleteCounts = [];
        $totalInserts           = $collectionInsertCount + $insertCount;
        $totalUpdates           = 0;
        $totalDeletes           = 0;

        foreach ($collection->get(CompilerResult::UPDATE)->getKeys() as $type) {
            if (!array_key_exists($type, $collectionUpdateCounts)) {
                $collectionUpdateCounts[$type] = 0;
            }
            $collectionUpdateCounts[$type] += count($collection->get(CompilerResult::UPDATE)->get($type));
        }

        foreach ($updateCounts as $type => $count) {
            $collectionCount = array_key_exists($type, $collectionUpdateCounts) ? $collectionUpdateCounts[$type] : 0;
            $totalUpdates    += ceil(($collectionCount + $count) % 200);
        }

        foreach ($collection->get(CompilerResult::DELETE)->getKeys() as $type) {
            if (!array_key_exists($type, $collectionDeleteCounts)) {
                $collectionDeleteCounts[$type] = 0;
            }
            $collectionDeleteCounts[$type] += count($collection->get(CompilerResult::DELETE)->get($type));
        }

        foreach ($deleteCounts as $type => $count) {
            $collectionCount = array_key_exists($type, $collectionDeleteCounts) ? $collectionDeleteCounts[$type] : 0;
            $totalDeletes    += ceil(($collectionCount + $count) % 200);
        }

        return 25 >= ($totalInserts + $totalUpdates + $totalDeletes);
    }

    /**
     * @param DependencyNode $node
     * @param int $insertCount
     * @param array $updateCounts
     * @param array $deleteCounts
     * @param array $insertTypes
     */
    private static function countSubRequests(
        DependencyNode $node,
        int &$insertCount,
        array &$updateCounts,
        array &$deleteCounts,
        array &$insertTypes
    ) {
        $item   = $node->getItem();
        $intent = $item->getIntent();
        $type   = $item->getMetadata()->getSObjectType();

        switch ($intent) {
            case CompilerResult::INSERT:
                ++$insertCount;
                $insertTypes[] = $type;
                break;
            case CompilerResult::UPDATE:
                $updateCounts[$type] = $updateCounts[$type] + 1 ?: 1;
                break;
            case CompilerResult::DELETE:
                $deleteCounts[$type] = $updateCounts[$type] + 1 ?: 1;
                break;
        }

        if (!$node->getDependencies()->isEmpty()) {
            foreach ($node->getDependencies() as $dependency) {
                self::countSubRequests($dependency, $insertCount, $updateCounts, $deleteCounts, $insertTypes);
            }
        }
    }

    /**
     * @param ItemizedCollection $collection
     * @param array $partitions
     *
     * @return array
     */
    private static function partitionInserts(
        ItemizedCollection $collection,
        array $partitions = []
    ): array {
        $waitList  = new ItemizedCollection();
        $partition = end($partitions);

        if (false === $partition) {
            $partitions[uniqid('insert_')] = $partition = new ItemizedCollection();
        }

        /** @var CompilerResult $item */
        foreach ($collection as $item) {
            $type  = $item->getMetadata()->getSObjectType();
            $types = null === $partition ? [] : $partition->getKeys();

            if ((false === array_search($type, $types) && count($types) === 5) || count($partition) === 200) {
                self::processWaitList($partitions, $waitList);
                // Check to see if we've created any room in the current partition
                if ((false === array_search($type, $types) && count($types) === 5) || count($partition) === 200) {
                    $partitions[uniqid('insert_')] = $partition = new ItemizedCollection();
                }
            }

            $referenceId = $item->getReferenceId();
            $partition->set($referenceId, $item, $type);
        }

        self::processWaitList($partitions, $waitList);

        if (!$waitList->isEmpty()) {
            if (count(array_diff($collection->getKeys(), $waitList->getKeys())) === 0) {
                $partitions[uniqid('insert_')] = new ItemizedCollection();
            }
            $partitions = self::partitionInserts($waitList, $partitions);
        }

        return $partitions;
    }

    private static function processWaitList(array $partitions, ItemizedCollection $waitList)
    {
        /** @var ItemizedCollection $currentPartition */
        $currentPartition = end($partitions);
        reset($partitions);
        /** @var CompilerResult $item */
        foreach ($currentPartition as $item) {
            $object    = $item->getSObject();
            $defRefIds = [];

            foreach ($object->getFields() as $value) {
                if ($value instanceof ReferencePlaceholder) {
                    $defRefIds[] = $value->getEntityRefId();
                }
            }

            foreach ($defRefIds as $refId) {
                if ($currentPartition->containsKey($refId)) {
                    if (!$waitList->contains($item)) {
                        $waitList->add($item, $item->getReferenceId());
                    }
                    $currentPartition->removeElement($item);
                    break;
                }

                $depPrevAdded = false;
                /** @var ItemizedCollection $partition */
                foreach ($partitions as $partition) {
                    if ($partition !== $currentPartition && $partition->containsKey($refId)) {
                        $depPrevAdded = true;
                    }
                }

                if (!$depPrevAdded) {
                    if (!$waitList->contains($item)) {
                        $waitList->add($item, $item->getReferenceId());
                    }
                    $currentPartition->removeElement($item);
                }
            }
        }
    }

    /**
     * @param ItemizedCollection $collection
     *
     * @return array
     */
    private static function partitionCollection(ItemizedCollection $collection)
    {
        $partitions = [];
        $types      = $collection->getKeys();

        foreach ($types as $type) {
            $items      = $collection->get($type);
            $partitions = array_merge($partitions, self::partition($items));
        }

        return $partitions;
    }

    /**
     * @param array $items
     *
     * @return array
     */
    private static function partition(array $items): array
    {
        $partitions = [];
        $offset     = 0;

        while (($slice = array_slice($items, $offset, 200, true))) {
            $partitions[uniqid('coll_')] = $slice;
            $offset                      += count($slice);
        }

        return $partitions;
    }

    /**
     * @param array $set
     * @param array $inserts
     */
    private static function hydrateReferencePlaceholders(array $set, array $inserts)
    {
        /** @var CompilerResult $item */
        foreach ($set as $items) {
            foreach ($items as $item) {
                $object = $item->getSObject();
                foreach ($object->getFields() as $field => $value) {
                    if ($value instanceof ReferencePlaceholder) {
                        list($row, $ref) = self::getReferenceIdForPlaceholder($value, $inserts);
                        if (null !== $ref) {
                            $reference      = new Reference($ref);
                            $object->$field = $reference->field('['.$row.'].id');
                        }
                    }
                }
            }
        }
    }

    /**
     * @param ReferencePlaceholder $placeholder
     * @param array $inserts
     *
     * @return array
     */
    private static function getReferenceIdForPlaceholder(ReferencePlaceholder $placeholder, array $inserts): array
    {
        $entityId = $placeholder->getEntityRefId();

        foreach ($inserts as $refId => $items) {
            $row = 0;
            /** @var CompilerResult $item */
            foreach ($items as $item) {
                if ($item->getReferenceId() === $entityId) {
                    return [$row, $refId];
                }

                ++$row;
            }
        }

        return [null, null];
    }
}
