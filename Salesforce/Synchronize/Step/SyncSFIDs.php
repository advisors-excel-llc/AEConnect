<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SyncSFIDs extends Step
{
    const NAME = 'aeconnect.sync_sfids';
    /**
     * @param EventDispatcherInterface $dispatcher
     */
    function execute(EventDispatcherInterface $dispatcher): void
    {
        $target = $this->syncEvent->getCurrentTarget();
        $event = new SyncTargetEvent($target, $this->syncEvent->getConnection());
        $dispatcher->dispatch($event, self::NAME);
    }

    function nextStep(): Step
    {
        if ($this->syncEvent->getConfig()->getPullConfiguration()->update && $this->syncEvent->getCurrentTarget()->canUpdate()) {
            return new UpdateEntityWithSObject();
        } else if ($this->syncEvent->getConfig()->getPullConfiguration()->create && $this->syncEvent->getCurrentTarget()->canCreateInDatabase()) {
            return new CreateEntityWithSObject();
        }
        return new Flush();
    }
}
