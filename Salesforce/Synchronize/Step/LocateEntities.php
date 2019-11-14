<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class LocateEntities extends Step
{
    const NAME = 'aeconnect.locate_entities';
    function execute(EventDispatcherInterface $dispatcher): void
    {
        $target = $this->syncEvent->getCurrentTarget();
        $event = new SyncTargetEvent($target, $this->syncEvent->getConnection());
        $dispatcher->dispatch($event, self::NAME);
    }

    function nextStep(): Step
    {
        return new EndStep();
    }
}
