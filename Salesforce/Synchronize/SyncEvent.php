<?php

namespace AE\ConnectBundle\Salesforce\Synchronize;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use Symfony\Contracts\EventDispatcher\Event;

class SyncEvent extends Event
{
    /** @var ConnectionInterface */
    private $connection;
    private $config;
    /** @var Target[] */
    private $targetMeta = [];

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * @return Configuration
     */
    public function getConfig(): Configuration
    {
        return $this->config;
    }

    /**
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param ConnectionInterface $connection
     */
    public function setConnection(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
    }

    public function addTarget(Target $target): void
    {
        $target->batchSize = $this->getConfig()->getBatchSize();
        $this->targetMeta[] = $target;
    }

    /**
     * @return Target
     */
    public function getCurrentTarget(): ?Target
    {
        return current($this->targetMeta);
    }

    /**
     * @return Target
     */
    public function nextTarget(): ?Target
    {
        return next($this->targetMeta) ? current($this->targetMeta) : null;
    }

    /**
     * @return array|Target[]
     */
    public function getTargets(): array
    {
        return $this->targetMeta;
    }

    public function hasRecordsToProcess(): bool
    {
        return !empty(current($this->targetMeta)->records);
    }

    public function hasUnprocessedQueries(): bool
    {
        return array_reduce($this->targetMeta, function($carry, Target $target) { return $carry || $target->queryComplete; }, false);
    }
}
