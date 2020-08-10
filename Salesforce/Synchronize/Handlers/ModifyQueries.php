<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use AE\ConnectBundle\Util\DestructuredQuery;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ModifyQueries implements SyncHandler
{
    use LoggerAwareTrait;

    /**
     * GetAllTargets constructor.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?: new NullLogger());
    }

    public function process(SyncEvent $event): void
    {
        $queries = $event->getConfig()->getQueries();
        $event->getConfig()->clearQueries();
        foreach ($queries as $target => $queryString) {
            $query = new DestructuredQuery($queryString);
            $classMetas = $event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($target);
            $this->handleAsterik($classMetas, $query);
            $this->handleRecordTypeWhereClause($classMetas, $query);
            $this->activateFields($classMetas, $query);
            if ($this->isQueryable($classMetas)) {
                $event->getConfig()->addQuery($target, $query->__toString());
            }
        }
    }

    /**
     * Changes out a * in a query for all of the fields that AEConnect is listening on.
     */
    private function handleAsterik(array $metadatas, DestructuredQuery $query)
    {
        if ('*' !== $query->getSelect()) {
            return;
        }
        $fields = [];
        foreach ($metadatas as $metadata) {
            $fields = array_merge($fields, $metadata->getPropertyMap());
        }
        $query->setSelect(implode(', ', $fields));
    }

    private function handleRecordTypeWhereClause(array $metadatas, DestructuredQuery $query)
    {
        $recordTypes = [];
        foreach ($metadatas as $metadata) {
            // If the metadata has a class-level RecordType annotation, let's use it to filter
            // but the moment there's metadata for the same type that doesn't have a class-level
            // RecordType annotation, we need to get records of any record type and filter them out locally
            $recordType = $metadata->getRecordType();
            if (null !== $recordTypes && null !== $recordType && null !== $recordType->getName()) {
                $recordTypes[] = $metadata->getRecordTypeId($recordType->getName());
            } else {
                $recordTypes = null;
            }
        }

        // There were no record type limitations given for this target so we don't wanna touch the query.
        if (empty($recordTypes)) {
            return;
        }

        //Lets construct our record type query in case we need it
        $recordTypePredicate = "RecordTypeId IN ('".implode("', '", $recordTypes)."')";
        //We need to carefully modify the query to include only the record types we need.
        //First lets see if there is already a where clause
        if (!$query->where) {
            //There isn't, so we just need to return our where clause appended to the query.
            $query->where = $recordTypePredicate;

            return;
        }

        if (false !== stripos($query->where, 'RECORDTYPE')) {
            //The query already includes a record type filter, best to leave the query alone.
            return;
        }
        // concat the record type prediacte onto the current where clause then.
        $query->where = "$recordTypePredicate AND ($query->where)";
    }

    /**
     * During a BULK SYNC, a user may have elected a subset of fields to include in the.
     *
     * @param Metadata[] $metadatas
     *
     * @return string
     */
    private function activateFields(array $metadatas, DestructuredQuery $query)
    {
        $fieldListStr = str_replace(' ', '', $query->getSelect());
        //These are all the fields we want active from our SELECT.
        $fields = explode(',', $fieldListStr);
        foreach ($metadatas as $metadata) {
            foreach ($metadata->getFieldMetadata() as $fieldMetadata) {
                if (false !== array_search(strtoupper($fieldMetadata->getField()), $fields)) {
                    $metadata->addActiveFieldMetadata($fieldMetadata);
                }
            }
        }
    }

    private function isQueryable(array $metadatas): bool
    {
        foreach ($metadatas as $metadata) {
            if (!$metadata->getDescribe() || !$metadata->getDescribe()->isQueryable()) {
                $this->logger->debug(
                    '#AECONNECT #generateQueries -> #process {obj} is not queryable',
                    ['obj' => $metadata->getClassName()]
                );

                return false;
            }

            return true;
        }

        return false;
    }
}
