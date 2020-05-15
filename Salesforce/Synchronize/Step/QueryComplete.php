<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class QueryComplete extends Step
{
    const NAME = 'aeconnect.query_complete';

    public function execute(EventDispatcherInterface $dispatcher): void
    {
        $target = $this->syncEvent->getCurrentTarget();
        $event = new SyncTargetEvent($target, $this->syncEvent->getConnection());
        $dispatcher->dispatch($event, self::NAME);
        $this->syncEvent->nextTarget();
    }

    public function nextStep(): Step
    {
        if ($this->syncEvent->hasUnprocessedQueries()) {
            //We are moving on to the next sobject for processing
            return new PullRecords();
        } else {
            if ($this->syncEvent->getConfig()->getPushConfiguration()->update || $this->syncEvent->getConfig()->getPushConfiguration()->create) {
                return new OutboundUpdate();
            }
            //All done!
            return new EndStep();
        }
    }
}
