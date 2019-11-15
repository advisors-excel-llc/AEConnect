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
        if ($this->syncEvent->getConfig()->getPullConfiguration()->sfidSync && $this->syncEvent->getCurrentTarget()->canUpdate()) {
            return new SyncSFIDs();
        } else if ($this->syncEvent->getConfig()->getPullConfiguration()->update && $this->syncEvent->getCurrentTarget()->canUpdate()) {
            return new UpdateEntityWithSObject();
        } else if ($this->syncEvent->getConfig()->getPullConfiguration()->create && $this->syncEvent->getCurrentTarget()->canCreateInDatabase()) {
            return new CreateEntityWithSObject();
        }
        return new Flush();
    }
}
