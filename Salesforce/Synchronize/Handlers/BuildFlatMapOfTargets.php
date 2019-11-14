<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Bulk\SObjectTreeMaker;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class BuildFlatMapOfTargets implements SyncHandler
{
    private $treeMaker;
    /**
     * GetAllTargets constructor.
     * @param SObjectTreeMaker $treeMaker
     */
    public function __construct(SObjectTreeMaker $treeMaker)
    {
        $this->treeMaker = $treeMaker;
    }

    /**
     * Builds a flat map of the all of the saelsforce SObject types we have, and then filters out that map to only include
     * any target types a user has specified wanting to work on.  Will keep ALL targets if the user specified no targets.
     * @param SyncEvent $event
     */
    public function process(SyncEvent $event): void
    {
        $connection = $event->getConnection();
        $map = $this->treeMaker->buildFlatMap($connection);

        if (!empty($event->getConfig()->getSObjectTargets())) {
            $map = array_intersect($map, $event->getConfig()->getSObjectTargets());
        }
        $event->getConfig()->setSObjectTargets($map);
    }
}
