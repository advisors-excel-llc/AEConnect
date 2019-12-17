<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Compiler\ObjectCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use JMS\Serializer\Exception\RuntimeException;

class UpdateEntityWithSObject implements SyncTargetHandler
{
    /** @var FieldCompiler  */
    private $fieldCompiler;
    /** @var ObjectCompiler */
    private $objectCompiler;
    /**
     * SyncSFIDs constructor.
     * @param FieldCompiler $fieldCompiler
     */
    public function __construct(FieldCompiler $fieldCompiler, ObjectCompiler $objectCompiler)
    {
        $this->fieldCompiler = $fieldCompiler;
        $this->objectCompiler = $objectCompiler;
    }


    public function process(SyncTargetEvent $event): void
    {
        $slowRecords = [];

        foreach ($event->getTarget()->records as $record) {
            if ($record->canUpdate()) {
                $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
                try {
                    $record->entity = $this->objectCompiler->fastCompile($classMeta, $record->sObject, $record->entity);
                } catch (RuntimeException $e) {
                    $record->warning = '#performance #serialization sObject to entity : '.$e->getMessage().
                        PHP_EOL.' will use transformers instead to achieve deserialization.';
                    $slowRecords[] = $record;
                    break;
                } catch (\Throwable $e) {
                    $record->error = $e->getMessage();
                }
            }
        }
        // Apply the field values from the SObject to the Entity the slow way if we failed to fast compile.
        foreach ($slowRecords as $record) {
            try {
                $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity(
                    $record->entity
                );
                $record->entity = $this->objectCompiler->slowCompile($classMeta, $record->sObject, true);
            } catch  (\Throwable $e) {
                $record->error = $e->getMessage();
            }
        }
    }
}
