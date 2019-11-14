<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;

interface SyncTargetHandler
{
    public function process(SyncTargetEvent $event): void;
}
