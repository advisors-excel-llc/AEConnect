<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Modules;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareTrait;

class Errors
{
    use LoggerAwareTrait;

    public function register(EventDispatcherInterface $dispatch)
    {
        $dispatch->addListener('aeconnect.flush', [$this, 'recordErrors'], 101);
    }

    public function recordErrors(SyncTargetEvent $event)
    {
        foreach ($event->getTarget()->getRecordsWithErrors() as $errorRecords)
        {
            $this->logger->error($errorRecords->error, $errorRecords->sObject->getFields());
        }
        foreach ($event->getTarget()->getRecordsWithWarnings() as $warningRecords)
        {
            $this->logger->error($warningRecords->error, $warningRecords->sObject->getFields());
        }
    }
}
