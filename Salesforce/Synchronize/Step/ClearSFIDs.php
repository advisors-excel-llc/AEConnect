<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ClearSFIDs extends Step
{
    const NAME = 'aeconnect.clear_sfid';
    function execute(EventDispatcherInterface $dispatcher): void
    {
        foreach ($this->syncEvent->getTargets() as $target) {
            $clearEvent = new SyncTargetEvent($target, $this->syncEvent->getConnection());
            $dispatcher->dispatch($clearEvent, self::NAME);
        }
    }

    function nextStep(): Step
    {
        $pull = $this->syncEvent->getConfig()->getPullConfiguration();
        if ($pull->needsDataHydrated()) {
            return new PullRecords();
        } else {
            if ($this->syncEvent->getConfig()->getPushConfiguration()->update || $this->syncEvent->getConfig()->getPushConfiguration()->create) {
                return new OutboundUpdate();
            }
            return new EndStep();
        }
    }
}
