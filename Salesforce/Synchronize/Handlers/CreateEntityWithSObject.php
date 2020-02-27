<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Compiler\ObjectCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use JMS\Serializer\Exception\RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateEntityWithSObject implements SyncTargetHandler
{
    /** @var ObjectCompiler */
    private $objectCompiler;

    /**
     * CreateEntityWithSObject constructor.
     * @param ObjectCompiler $objectCompiler
     */
    public function __construct(ObjectCompiler $objectCompiler)
    {
        $this->objectCompiler = $objectCompiler;
    }

    public function process(SyncTargetEvent $event): void
    {
        $classMetas = $event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($event->getTarget()->name);
        $records = [];
        foreach ($classMetas as $classMeta) {
            foreach ($event->getTarget()->records as $record) {
                if ($record->canCreateInDatabase()) {
                    try {
                        $newRecord = new Record($record->sObject, $this->objectCompiler->deserializeSobject($classMeta, $record->sObject));
                        $this->objectCompiler->SFIDCompile($classMeta, $newRecord->sObject, $newRecord->entity);
                        $newRecord->needCreate = true;
                        $records[] = $newRecord;
                    } catch (RuntimeException $e) {
                        $record->error = '#serialization sObject to entity : ' . $e->getMessage();
                        break;
                    } catch (\Throwable $e) {
                        $record->error = $e->getMessage();
                    }
                } else {
                    $records[] = $record;
                }
            }
        }
        $event->getTarget()->records = $records;
    }
}
