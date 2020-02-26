<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Compiler\ObjectCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

class CustomTransformHandler implements SyncTargetHandler
{
    /** @var ObjectCompiler */
    private $objectCompiler;

    /**
     * CustomTransformHandler constructor.
     * @param ObjectCompiler $objectCompiler
     */
    public function __construct(ObjectCompiler $objectCompiler)
    {
        $this->objectCompiler = $objectCompiler;
    }

    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getTarget()->records as $record) {
            $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
            $this->objectCompiler->runTransformers($classMeta, $record->sObject, $record->entity);
        }
    }
}
