<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PullRecords extends Step
{
    const NAME = 'aeconnect.pull_records';
    function execute(EventDispatcherInterface $dispatcher): void
    {
        $target = $this->syncEvent->getCurrentTarget();
        if (!$target->queryComplete) {
            $event = new SyncTargetEvent($target, $this->syncEvent->getConnection());
            $dispatcher->dispatch($event, self::NAME);
            // once the dispatched action returns no results, we know that this particular target's query is complete
            // and we can mark it as such here.
            if (empty($event->getTarget()->records)) {
                $target->queryComplete = true;
                $this->syncEvent->nextTarget();
            }
        }
    }

    function nextStep(): Step
    {
        if ($this->syncEvent->hasRecordsToProcess()) {
            // OK, now Locate em!
            return new LocateEntities();
        } else if($this->syncEvent->hasUnprocessedQueries()) {
            //We are moving on to the next sobject for processing
            return $this;
        } else {
            //All done!
            return new EndStep();
        }
    }
}
