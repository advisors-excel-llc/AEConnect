<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Salesforce\Bulk\SfidReset;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

class ClearSFIDs implements SyncTargetHandler
{
    private $sfidReset;

    /**
     * clearSFIDs constructor.
     * @param SfidReset $sfidReset
     */
    public function __construct(SfidReset $sfidReset)
    {
        $this->sfidReset = $sfidReset;
    }

    /**
     * @param SyncTargetEvent $event
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($event->getTarget()->name) as $metadata) {
            $describeSObject = $metadata->getDescribe();
            // We only want to clear the Ids on objects that will be acted upon
            if (!$describeSObject->isQueryable()
                || !$describeSObject->isCreateable() || !$describeSObject->isUpdateable()
            ) {
                continue;
            }
            $class         = $metadata->getClassName();
            $fieldMetadata = $metadata->getMetadataForField('Id');

            if (null == $fieldMetadata) {
                continue;
            }
            $this->sfidReset->doClear($event->getConnection(), $class, $fieldMetadata);
            $event->getTarget()->sfidsCleared = true;
        }
    }
}
