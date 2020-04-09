<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
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
        foreach ($queries as $target => $query) {
            $classMetas = $event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($target);
            $query = $this->handleUpperCasing($query);
            $query = $this->handleAsterik($classMetas, $query);
            $query = $this->handleRecordTypeWhereClause($classMetas, strtoupper($target), $query);
            $this->activateFields($classMetas, strtoupper($target), $query);
            if ($this->isQueryable($classMetas, $query)) {
                $event->getConfig()->addQuery($target, $query);
            }
        }
    }

    /**
     * Upper cases a query, but does not upper case parameters of a query like SFIDs.
     */
    private function handleUpperCasing(string $query): string
    {
        $queryParts = explode("'", $query);
        // This capitalizes every other string, and since we exploded on ',
        // we are capitalizing everything that is not surrounded in quotes.
        $cappedParts = array_map(
            function (string $part, int $idx) { return (bool) ($idx % 2) ? $part : strtoupper($part); },
            $queryParts,
            array_keys($queryParts)
        );

        return implode("'", $cappedParts);
    }

    /**
     * Changes out a * in a query for all of the fields that AEConnect is listening on.
     */
    private function handleAsterik(array $metadatas, string $query): string
    {
        if (false === strpos($query, '*')) {
            return $query;
        }
        $fields = [];
        foreach ($metadatas as $metadata) {
            $fields = array_merge($fields, $metadata->getPropertyMap());
        }

        return str_replace('*', strtoupper(implode(',', $fields)), $query);
    }

    private function handleRecordTypeWhereClause(array $metadatas, string $target, string $query): string
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
            return $query;
        }

        //Lets construct our record type query in case we need it
        $recordTypePredicate = "WHERE RecordTypeId IN ('".implode("', '", $recordTypes)."')";
        //We need to carefully modify the query to include only the record types we need.
        //First lets see if there is already a where clause
        if ($wherePosition = false === strpos($query, 'WHERE')) {
            //There isn't, so we just need to return our where clause appended to the query.
            return implode($target.' '.$recordTypePredicate.' ', explode($target, $query, 2));
        }

        // Check if the where clause includes RECORDTYPE already or not.
        $whereClause = substr($query, $wherePosition);
        //A user could be using a record type as an order by field or something, so lets make sure that is cut out as well
        // for when we want to check if record type truly exists as a user submitted query or not.
        if ($orderByPosition = strpos($query, 'ORDER BY')) {
            $whereClause = substr($whereClause, 0, $orderByPosition);
        }

        if (false !== strpos($whereClause, 'RECORDTYPE')) {
            //The query already includes a record type filter, best to leave the query alone.
            return $query;
        }

        return str_replace('WHERE', $recordTypePredicate.' AND ', $query);
    }

    /**
     * During a BULK SYNC, a user may have elected a subset of fields to include in the.
     *
     * @param Metadata[] $metadatas
     *
     * @return string
     */
    private function activateFields(array $metadatas, string $target, string $query)
    {
        $fieldListStr = str_replace(' ', '', substr($query, strpos($query, 'SELECT') + 7, strpos($query, 'FROM '.$target) - 7));
        //These are all the fields we want active from our SELECT.  Anything outside of that we want to pretend they don't exist.
        $fields = explode(',', $fieldListStr);
        foreach ($metadatas as $metadata) {
            foreach ($metadata->getFieldMetadata() as $fieldMetadata) {
                if (false !== array_search(strtoupper($fieldMetadata->getField()), $fields)) {
                    $metadata->addActiveFieldMetadata($fieldMetadata);
                }
            }
        }
    }

    private function isQueryable(array $metadatas, string $query): bool
    {
        foreach ($metadatas as $metadata) {
            if (!$metadata->getDescribe()->isQueryable()) {
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
