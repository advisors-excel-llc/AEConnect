<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 4:17 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Salesforce\Transformer\Util\SfidFinder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Proxy\Proxy;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Validator\Exception\UnsupportedMetadataException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AssociationTransformer extends AbstractTransformerPlugin
{
    use LoggerAwareTrait;
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var RegistryInterface
     */
    private $managerRegistry;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var SfidFinder
     */
    private $sfidFinder;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $managerRegistry,
        ValidatorInterface $validator,
        SfidFinder $sfidFinder,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->managerRegistry   = $managerRegistry;
        $this->validator         = $validator;
        $this->sfidFinder        = $sfidFinder;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param TransformerPayload $payload
     *
     * @return bool
     */
    public function supports(TransformerPayload $payload): bool
    {
        if (null === $payload->getValue()) {
            return false;
        }

        $connection = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());

        if (null === $connection) {
            return false;
        }

        $classMetadata = $payload->getClassMetadata();
        $propertyName  = $payload->getPropertyName();
        $fieldName     = $payload->getFieldName();

        // If the field is not an association field, skip the forthcoming mapping exception
        if (!$classMetadata->hasAssociation($propertyName)) {
            return false;
        }

        try {
            $association = $classMetadata->getAssociationMapping($propertyName);
            $metadata    = $this->getMetadataForClass(
                $classMetadata,
                $propertyName,
                $connection->getMetadataRegistry()
            );

            return !(null === $metadata
                || !$association['isOwningSide']
                || $association['type'] & ClassMetadataInfo::TO_MANY
                || null === $fieldName
                || 'Id' === $fieldName);
        } catch (MappingException $e) {
            $this->logger->error(
                '{msg}',
                [
                    'msg' => $e->getMessage(),
                ]
            );
            $this->logger->debug(
                '{trace}',
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    /**
     * @param ClassMetadata $classMetadata
     * @param string $propertyName
     * @param MetadataRegistry $registry
     *
     * @return Metadata|null
     * @throws MappingException
     */
    protected function getMetadataForClass(
        ClassMetadata $classMetadata,
        string $propertyName,
        MetadataRegistry $registry
    ): ?Metadata {
        $association = $classMetadata->getAssociationMapping($propertyName);
        $className   = $association['targetEntity'];
        $metadata    = $registry->findMetadataByClass($className);

        if (null === $metadata && !$classMetadata->isInheritanceTypeNone()) {
            foreach ($classMetadata->discriminatorMap as $class) {
                if (null !== ($nextMetadata = $registry->findMetadataByClass($class))) {
                    return $nextMetadata;
                }
            }
        } elseif (null === $metadata && $classMetadata->isMappedSuperclass) {
            foreach ($classMetadata->subClasses as $class) {
                if (null !== ($nextMetadata = $registry->findMetadataByClass($class))) {
                    return $nextMetadata;
                }
            }
        }

        return $metadata;
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws MappingException
     */
    protected function transformInbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        try {
            $metadata = $this->getMetadataForClass(
                $classMetadata,
                $payload->getPropertyName(),
                $connection->getMetadataRegistry()
            );

            if (null === $metadata) {
                $payload->setValue(null);

                return;
            }
        } catch (UnsupportedMetadataException $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
            $payload->setValue(null);

            return;
        }
        $sfidProperty = $metadata->getIdFieldProperty();

        // If the target entity doesn't have an SFID to lookup, can't locate the source
        if (null === $sfidProperty) {
            $payload->setValue(null);

            return;
        }

        /** @var EntityManager $manager */
        $manager = $this->managerRegistry->getManagerForClass($className);
        $repo    = $manager->getRepository($className);
        /** @var ClassMetadata $classMetadata */
        $classMetadata = $manager->getClassMetadata($className);
        $value         = $payload->getValue();
        $entity        = null;

        if ($classMetadata->hasField($sfidProperty)) {
            $entity = $repo->findOneBy([$sfidProperty => $value]);
        } elseif ($classMetadata->hasAssociation($sfidProperty)) {
            $targetClass = $classMetadata->getAssociationTargetClass($sfidProperty);
            /** @var ClassMetadata $targetMetadata */
            $targetMetadata = $this->managerRegistry->getManagerForClass($targetClass)->getClassMetadata($targetClass);
            $idField        = $targetMetadata->getSingleIdentifierFieldName();
            $sfid           = $this->sfidFinder->find($value, $targetClass);

            // If there's an SFID, let's locate the object it's associated with
            if (null !== $sfid) {
                $builder = $repo->createQueryBuilder('o');
                $builder->join("o.$sfidProperty", "s")
                        ->where($builder->expr()->eq("s.$idField", ":id"))
                        ->setParameter("id", $targetMetadata->getFieldValue($sfid, $idField))
                ;

                try {
                    $entity = $builder->getQuery()->getOneOrNullResult();
                } catch (ORMException $e) {
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                }
            }
        }

        $payload->setValue($entity);
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws MappingException
     */
    protected function transformOutbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        try {
            $metadata = $this->getMetadataForClass(
                $classMetadata,
                $payload->getPropertyName(),
                $connection->getMetadataRegistry()
            );

            if (null === $metadata) {
                $payload->setValue(null);

                return;
            }
        } catch (UnsupportedMetadataException $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
            $payload->setValue(null);

            return;
        }

        $sfidProperty = $metadata->getIdFieldProperty();

        // If the target entity doesn't have a SFID, we can't send Salesforce the ID
        if (null === $sfidProperty) {
            $payload->setValue(null);

            return;
        }

        /** @var EntityManager $manager */
        $manager            = $this->managerRegistry->getManagerForClass($className);
        $associatedMetadata = $manager->getClassMetadata($className);
        $entity             = $payload->getValue();

        // Ensure that a Proxy is initialized
        if ($entity instanceof Proxy && !$entity->__isInitialized()) {
            $entity->__load();
        }

        $sfid = $associatedMetadata->getFieldValue($entity, $sfidProperty);

        if (null === $sfid) {
            $groups = [
                'ae_connect_outbound',
                'ae_connect_outbound.'.$connection->getName(),
            ];

            if ($connection->isDefault() && 'default' != $connection->getName()) {
                $groups[] = 'ae_connect_outbound.default';
            }

            $messages = $this->validator->validate(
                $entity,
                null,
                $groups
            );

            if (count($messages) === 0) {
                $assocRefId = spl_object_hash($entity);
                $sfid       = new ReferencePlaceholder($assocRefId);
            }
        } elseif ($sfid instanceof SalesforceIdEntityInterface) {
            $sfid = $sfid->getSalesforceId();
        } elseif ($sfid instanceof Collection) {
            $sfid = $sfid->filter(
                function (SalesforceIdEntityInterface $entity) use ($connection) {
                    $conn = $entity->getConnection();

                    if ($conn instanceof ConnectionEntityInterface) {
                        return $conn->getName() === $connection->getName();
                    }

                    return $connection->getName() === $conn;
                }
            )->first()
            ;

            if ($sfid instanceof SalesforceIdEntityInterface) {
                $sfid = $sfid->getSalesforceId();
            } else {
                $sfid = null;
            }
        }

        $payload->setValue($sfid);
    }
}
