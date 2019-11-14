<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GenerateQueries implements SyncHandler
{
    use LoggerAwareTrait;
    /**
     * GetAllTargets constructor.
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?: new NullLogger());
    }

    public function process(SyncEvent $event): void
    {
        $connection = $event->getConnection();
        $metadataRegistry = $connection->getMetadataRegistry();

        $fields = [];
        $recordTypes = [];

        foreach ($event->getConfig()->getSObjectTargets() as $type) {
            foreach ($metadataRegistry->findMetadataBySObjectType($type) as $metadata) {
                if (!$metadata->getDescribe()->isQueryable()) {
                    $this->logger->debug('#AECONNECT #generateQueries -> #process {obj} is not queryable', ['obj' => $type]);
                    continue;
                }
                $fields = array_merge($fields, $metadata->getPropertyMap());
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
            if (empty($fields)) {
                return;
            }
            $fields = array_unique($fields);
            $query = "SELECT ".implode(',', $fields)." FROM $type";

            if (!empty($recordTypes)) {
                $query .= " WHERE RecordTypeId IN ('".implode("', '", $recordTypes)."')";
            }
            $event->getConfig()->addQuery($type, $query);
        }
    }
}
