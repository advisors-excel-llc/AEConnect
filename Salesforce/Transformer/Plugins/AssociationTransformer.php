<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 4:17 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\ReferenceIdGenerator;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Symfony\Bridge\Doctrine\ManagerRegistry;

class AssociationTransformer implements TransformerPluginInterface
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var ManagerRegistry
     */
    private $managerRegistry;

    public function __construct(ConnectionManagerInterface $connectionManager, ManagerRegistry $managerRegistry)
    {
        $this->connectionManager = $connectionManager;
        $this->managerRegistry   = $managerRegistry;
    }

    public function supports(TransformerPayload $payload): bool
    {
        if (null === $payload->getPayload()) {
            return false;
        }

        $connection = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());

        if (null === $connection) {
            return false;
        }

        $classMetadata = $payload->getClassMetadata();
        try {
            $association = $classMetadata->getAssociationMapping($payload->getPropertyName());
            $className   = $association['targetEntity'];
            $metadata    = $connection->getMetadataRegistry()->findMetadataByClass($className);

            if (null === $metadata
                || !$association['isOwningSide']
                || $association['type'] & ClassMetadataInfo::TO_MANY) {
                return false;
            }

            $sfidProperty = $metadata->getIdFieldProperty();

            if (null === $sfidProperty) {
                return false;
            }

            return true;
        } catch (MappingException $e) {
            return false;
        }
    }

    public function transformInbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);
        $sfidProperty  = $metadata->getIdFieldProperty();
        /** @var EntityManager $manager */
        $manager            = $this->managerRegistry->getManagerForClass($className);
        $repo = $manager->getRepository($className);

        $entity = $repo->findOneBy([$sfidProperty => $payload->getPayload()]);

        $payload->setPayload($entity);
    }

    public function transformOutbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);
        $sfidProperty  = $metadata->getIdFieldProperty();
        /** @var EntityManager $manager */
        $manager            = $this->managerRegistry->getManagerForClass($className);
        $associatedMetadata = $manager->getClassMetadata($className);
        $entity             = $payload->getPayload();
        $sfid               = $associatedMetadata->getFieldValue($entity, $sfidProperty);

        if (null === $sfid) {
            $assocRefId = ReferenceIdGenerator::create($entity, $metadata);
            $sfid = new ReferencePlaceholder($assocRefId, 'id');
        }

        $payload->setPayload($sfid);
    }

}
