<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 10:09 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

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

    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        InboundBulkQueue $inboundBulkQueue,
        OutboundBulkQueue $outboundBulkQueue,
        RegistryInterface $registry
    ) {
        $this->connectionManager = $connectionManager;
        $this->inboundQueue      = $inboundBulkQueue;
        $this->outboundQueue     = $outboundBulkQueue;
        $this->registry          = $registry;
    }

    public function process(
        ?string $connectionName,
        array $types = [],
        int $updateFlag = self::UPDATE_NONE,
        int $outboundBatchSize = 2000
    ) {
        $connections = $this->connectionManager->getConnections();

        if (null !== $connectionName
            && (null !== ($connection = $this->connectionManager->getConnection($connectionName)))
        ) {
            $connections = [$connection];
        }

        foreach ($connections as $connection) {
            $this->clearSalesforceIds($connection);
            $this->inboundQueue->process($connection, $types, self::UPDATE_INCOMING & $updateFlag);
            $this->outboundQueue->process($connection, $types, $outboundBatchSize, self::UPDATE_OUTGOING & $updateFlag);
        }
    }

    /**
     * Clearing Salesforce Ids is important so that the IDs that are created during the incoming process
     * are able to reflect what is and what is not created in Salesforce that way the outbound process can
     * only create new records, if that option is chosen
     * @param ConnectionInterface $connection
     */
    private function clearSalesforceIds(ConnectionInterface $connection)
    {
        foreach ($connection->getMetadataRegistry()->getMetadata() as $metadata) {
            $describeSObject = $metadata->getDescribe();
            // We only want to clear the Ids on objects that will be acted upon
            if (!$describeSObject->isQueryable()
                || !$describeSObject->isCreateable() || !$describeSObject->isUpdateable()
            ) {
                continue;
            }

            $class = $metadata->getClassName();
            $fieldMetadata = $metadata->getMetadataForField('Id');

            $manager = $this->registry->getManagerForClass($class);
            $repo = $manager->getRepository($class);
            $offset = 0;

            while (count(($entities = $repo->findBy([], null, 200, $offset))) > 0) {
                foreach ($entities as $entity) {
                    $fieldMetadata->setValueForEntity($entity, null);
                }
                $manager->flush();
                $offset += count($entities);
            }
        }
    }
}
