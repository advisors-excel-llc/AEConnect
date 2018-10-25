<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 10:09 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;

class BulkDataProcessor
{
    public const UPDATE_NONE     = 0;
    public const UPDATE_INCOMING = 1;
    public const UPDATE_OUTGOING = 2;
    public const UPDATE_BOTH     = 3;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var InboundBulkQueue
     */
    private $inboundQueue;

    /**
     * @var OutboundBulkQueue
     */
    private $outboundQueue;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        InboundBulkQueue $inboundBulkQueue,
        OutboundBulkQueue $outboundBulkQueue
    ) {
        $this->connectionManager = $connectionManager;
        $this->inboundQueue      = $inboundBulkQueue;
        $this->outboundQueue     = $outboundBulkQueue;
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
            $this->outboundQueue->process($connection, $types, self::UPDATE_OUTGOING & $updateFlag);
        }
    }
}
