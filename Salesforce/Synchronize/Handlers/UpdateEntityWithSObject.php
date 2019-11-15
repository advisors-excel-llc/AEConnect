<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

class UpdateEntityWithSObject implements SyncTargetHandler
{
    /** @var FieldCompiler  */
    private $fieldCompiler;
    /**
     * SyncSFIDs constructor.
     * @param FieldCompiler $fieldCompiler
     */
    public function __construct(FieldCompiler $fieldCompiler)
    {
        $this->fieldCompiler = $fieldCompiler;
    }


    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getTarget()->records as $record) {
            if ($record->canUpdate()) {
                $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
                // Apply the field values from the SObject to the Entity
                foreach ($record->sObject->getFields() as $field => $value) {
                    if ($field === 'Id' || null === ($fieldMetadata = $classMeta->getMetadataForField($field))) {
                        // ID is synced during another process so we will ignore that field if it shows up.
                        continue;
                    }

                    $newValue = $this->fieldCompiler->compileInbound(
                        $value,
                        $record->sObject,
                        $fieldMetadata,
                        $record->entity
                    );

                    if (null !== $newValue) {
                        $fieldMetadata->setValueForEntity($record->entity, $newValue);
                    }
                }
            }
        }
    }
}
