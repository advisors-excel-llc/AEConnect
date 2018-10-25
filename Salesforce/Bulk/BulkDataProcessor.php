<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 10:09 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class BulkDataProcessor
{
    use LoggerAwareTrait;

    public const UPDATE_NONE     = 0;
    public const UPDATE_INCOMING = 1;
    public const UPDATE_OUTGOING = 2;
    public const UPDATE_BOTH     = 3;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var BulkEntity
     */
    private $bulkEntity;

    /**
     * @var InboundBulkQueue
     */
    private $inboundQueue;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        SalesforceConnector $connector,
        BulkEntity $bulkEntity,
        InboundBulkQueue $inboundBulkQueue,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->connector         = $connector;
        $this->bulkEntity        = $bulkEntity;
        $this->inboundQueue      = $inboundBulkQueue;

        if (null !== $logger) {
            $this->setLogger($logger);
        }
    }

    public function process(?string $connectionName, array $types = [], int $updateFlag = self::UPDATE_NONE)
    {
        $connections = $this->connectionManager->getConnections();

        if (null !== $connectionName
            && (null !== ($connection = $this->connectionManager->getConnection($connectionName)))
        ) {
            $connections = [$connection];
        }

        foreach ($connections as $connection) {
            $this->inboundQueue->process($connection, $types, self::UPDATE_INCOMING & $updateFlag);
        }
    }
}
