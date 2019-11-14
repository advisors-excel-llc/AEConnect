<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;

class FindConnection implements SyncHandler
{
    private $connectionManager;

    public function __construct(ConnectionManagerInterface $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    public function process(SyncEvent $event): void
    {
        $event->setConnection($this->connectionManager->getConnection($event->getConfig()->getConnectionName()));
    }
}
