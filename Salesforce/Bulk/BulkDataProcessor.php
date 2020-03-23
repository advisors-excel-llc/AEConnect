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
use Doctrine\ORM\Mapping\MappingException;

class BulkDataProcessor
{
    public const UPDATE_NONE     = 0;
    public const UPDATE_INCOMING = 1;
    public const UPDATE_OUTGOING = 2;
    public const UPDATE_BOTH     = 3;
    public const UPDATE_SFIDS    = 4;
    public const INSERT_NEW      = 8;

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

    /**
     * @var SfidReset
     */
    private $sfidReset;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        InboundBulkQueue $inboundBulkQueue,
        OutboundBulkQueue $outboundBulkQueue,
        SfidReset $sfidReset,
        SalesforceConnector $connector
    ) {
        $this->connectionManager = $connectionManager;
        $this->inboundQueue      = $inboundBulkQueue;
        $this->outboundQueue     = $outboundBulkQueue;
        $this->sfidReset         = $sfidReset;
        $this->connector         = $connector;
    }

    /**
     * @param null|string $connectionName
     * @param array $types
     * @param int $updateFlag
     *
     * @throws MappingException
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(
        ?string $connectionName,
        array $types = [],
        int $updateFlag = self::UPDATE_NONE
    ) {
        $connections = $this->connectionManager->getConnections();

        if (null !== $connectionName
            && (null !== ($connection = $this->connectionManager->getConnection($connectionName)))
        ) {
            if ($connection->isActive()) {
                $connections = [$connection];
            }
        }

        $this->connector->disable();

        foreach ($connections as $connection) {
            if ($updateFlag & self::UPDATE_SFIDS) {
                $this->sfidReset->clearIds($connection, $types);
            }
            $this->inboundQueue->process(
                $connection,
                $types,
                self::UPDATE_INCOMING & $updateFlag,
                self::INSERT_NEW & $updateFlag
            );
            $this->outboundQueue->process($connection, $types, self::UPDATE_OUTGOING & $updateFlag);
        }

        $this->connector->enable();
    }
}
