<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

class LocateEntities implements SyncTargetHandler
{
    /**
     * @var EntityLocater
     */
    private $locater;

    public function __construct(EntityLocater $locater)
    {
        $this->locater = $locater;
    }

    public function process(SyncTargetEvent $event): void
    {
        $this->locater->locateEntities($event->getTarget(), $event->getConnection());
    }
}
