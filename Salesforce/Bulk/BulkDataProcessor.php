<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 10:09 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Bridge\Doctrine\RegistryInterface;

class BulkDataProcessor
{
    public const UPDATE_NONE     = 0;
    public const UPDATE_INCOMING = 1;
    public const UPDATE_OUTGOING = 2;
    public const UPDATE_BOTH     = 3;
    public const UPDATE_SFIDS    = 4;

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

    /**
     * @var SalesforceConnector
     */
    private $connector;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        InboundBulkQueue $inboundBulkQueue,
        OutboundBulkQueue $outboundBulkQueue,
        RegistryInterface $registry,
        SalesforceConnector $connector
    ) {
        $this->connectionManager = $connectionManager;
        $this->inboundQueue      = $inboundBulkQueue;
        $this->outboundQueue     = $outboundBulkQueue;
        $this->registry          = $registry;
        $this->connector         = $connector;
    }

    /**
     * @param null|string $connectionName
     * @param array $types
     * @param int $updateFlag
     *
     * @throws MappingException
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
                $this->clearSalesforceIds($connection);
            }
            $this->inboundQueue->process($connection, $types, self::UPDATE_INCOMING & $updateFlag);
            $this->outboundQueue->process($connection, $types, self::UPDATE_OUTGOING & $updateFlag);
        }

        $this->connector->enable();
    }

    /**
     * Clearing Salesforce Ids is important so that the IDs that are created during the incoming process
     * are able to reflect what is and what is not created in Salesforce that way the outbound process can
     * only create new records, if that option is chosen
     *
     * @param ConnectionInterface $connection
     * @throws MappingException
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

            $class         = $metadata->getClassName();
            $fieldMetadata = $metadata->getMetadataForField('Id');

            $manager = $this->registry->getManagerForClass($class);
            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($class);
            $association = null;
            $targetManager = null;

            if ($classMetadata->hasAssociation($fieldMetadata->getProperty())) {
                $association = $classMetadata->getAssociationMapping($fieldMetadata->getProperty());
                $targetManager = $this->registry->getManagerForClass($association['targetEntity']);
            }
            $repo    = $manager->getRepository($class);
            $offset  = 0;

            while (count(($entities = $repo->findBy([], null, 200, $offset))) > 0) {
                foreach ($entities as $entity) {
                    if (null === $association || $association['type'] & ClassMetadata::TO_ONE) {
                        if (null !== $targetManager && ($val = $fieldMetadata->getValueFromEntity($entity))) {
                            $targetManager->remove($val);
                        }
                        $fieldMetadata->setValueForEntity($entity, null);
                    } else {
                        /** @var ArrayCollection|SalesforceIdEntityInterface[] $sfids */
                        $sfids = $fieldMetadata->getValueFromEntity($entity);
                        foreach ($sfids as $sfid) {
                            $conn = $sfid->getConnection();
                            if ($conn instanceof ConnectionEntityInterface) {
                                if ($conn->getName() === $connection->getName()) {
                                    $sfids->removeElement($sfid);
                                    $targetManager->remove($sfid);
                                }
                            } elseif ($conn === $connection->getName()) {
                                $sfids->removeElement($sfid);
                                $targetManager->remove($sfid);
                            }
                        }

                        $fieldMetadata->setValueForEntity($entity, $sfids);
                    }
                }
                $manager->flush();
                $manager->clear($class);
                $offset += count($entities);

                if (null !== $targetManager) {
                    $targetManager->flush();
                    $targetManager->clear($association['targetEntity']);
                }
            }
        }
    }
}
