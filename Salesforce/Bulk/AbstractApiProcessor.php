<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 1:52 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

abstract class AbstractApiProcessor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var SalesforceConnector
     */
    protected $connector;

    /**
     * @var int
     */
    protected $batchSize = 50;

    /**
     * @var BulkProgress
     */
    protected $progress;

    public function __construct(
        SalesforceConnector $connector,
        BulkProgress $progress,
        int $batchSize = 50
    ) {
        $this->connector = $connector;
        $this->progress  = $progress;
        $this->batchSize = $batchSize;
        $this->logger    = new NullLogger();
    }

    /**
     * @return SalesforceConnector
     */
    public function getConnector(): SalesforceConnector
    {
        return $this->connector;
    }

    /**
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * @return BulkProgress
     */
    public function getProgress(): BulkProgress
    {
        return $this->progress;
    }

    /**
     * @param string $objectType
     * @param ConnectionInterface $connection
     * @param bool $updateEntities
     * @param $objects
     *
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     */
    protected function receiveObjects(
        string $objectType,
        ConnectionInterface $connection,
        bool $updateEntities,
        $objects
    ): void {
        $count = count($objects);
        $this->logger->debug(
            'Saving {count} {type} records for connection "{conn}"',
            [
                'count' => $count,
                'type'  => $objectType,
                'conn'  => $connection->getName(),
            ]
        );
        $this->connector->enable();
        $this->connector->receive(
            $objects,
            SalesforceConsumerInterface::UPDATED,
            $connection->getName(),
            $updateEntities
        );
        $this->connector->disable();

        $progress = $this->progress->getProgress($objectType) + $count;
        $this->progress->updateProgress($objectType, $progress);
    }

    abstract public function process(
        ConnectionInterface $connection,
        string $objectType,
        string $query,
        bool $updateEntities,
        bool $insertEntities = false
    );
}
