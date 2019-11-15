<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

class SyncSFIDs implements SyncTargetHandler
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
        $connection = $event->getConnection();
        foreach ($event->getTarget()->records as $record) {
            if ($record->canUpdate()) {
                $classMeta = $connection->getMetadataRegistry()->findMetadataForEntity($record->entity);
                $fieldMetadata = $classMeta->getMetadataForField('Id');
                $newValue = $this->fieldCompiler->compileInbound(
                    $record->sObject->getId(),
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
