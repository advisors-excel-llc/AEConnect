<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Bulk\OutboundBulkQueue;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class OutboundUpdate implements SyncHandler
{
    private $queue;

    public function __construct(OutboundBulkQueue $queue)
    {
        $this->queue = $queue;
    }

    public function process(SyncEvent $event): void
    {
        $this->queue->process(
            $event->getConnection(),
            $event->getConfig()->getSObjectTargets(),
            $event->getConfig()->getPushConfiguration()->update,
            $event->getConfig()->getPushConfiguration()->create
        );
    }
}
