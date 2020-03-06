<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GenerateQueries implements SyncHandler
{
    use LoggerAwareTrait;
    /**
     * GetAllTargets constructor.
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?: new NullLogger());
    }

    public function process(SyncEvent $event): void
    {
        foreach ($event->getConfig()->getSObjectTargets() as $type) {
            $query = "SELECT * FROM $type";
            $event->getConfig()->addQuery($type, $query);
        }
    }
}
