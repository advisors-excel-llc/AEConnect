<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class UpdateEntityWithSObject extends Step
{
    const NAME = 'aeconnect.update_entity_with_sobject';

    function execute(EventDispatcherInterface $dispatcher): void
    {
        $target = $this->syncEvent->getCurrentTarget();
        $event = new SyncTargetEvent($target, $this->syncEvent->getConnection());
        $dispatcher->dispatch($event, self::NAME);
    }

    function nextStep(): Step
    {
        if ($this->syncEvent->getConfig()->getPullConfiguration()->create && $this->syncEvent->getCurrentTarget()->canCreateInDatabase()) {
            return new CreateEntityWithSObject();
        }
        return new TransformStep();
    }
}
