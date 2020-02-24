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
        foreach ($event->getTarget()->records as $record) {
            if ($record->canUpdate()) {
                $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
                try {
                    $record->entity = $this->objectCompiler->deserializeSobject($classMeta, $record->sObject, $record->entity);
                    $record->needUpdate = true;
                } catch (RuntimeException $e) {
                    $record->warning = '#serialization sObject to entity : '.$e->getMessage();
                    break;
                } catch (\Throwable $e) {
                    $record->error = $e->getMessage();
                }
            }
        }
    }
}
