<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

interface SyncHandler
{
    public function process(SyncEvent $event) : void;
}
