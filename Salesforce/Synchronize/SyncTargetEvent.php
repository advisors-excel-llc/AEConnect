<?php

namespace AE\ConnectBundle\Salesforce\Synchronize;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use Symfony\Contracts\EventDispatcher\Event;

class SyncTargetEvent extends Event
{
    /** @var Target */
    private $target;
    /** @var ConnectionInterface */
    private $connection;

    public function __construct(Target $target, ConnectionInterface $connection)
    {
        $this->target = $target;
        $this->connection = $connection;
    }

    public function getTarget(): Target
    {
        return $this->target;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
