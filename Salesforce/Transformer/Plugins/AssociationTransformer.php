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
            $metadata    = $this->getMetadataForClass($payload, $connection->getMetadataRegistry());

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
     * @param TransformerPayload $payload
     * @param MetadataRegistry $registry
     *
     * @throws MappingException
     *
     * @return Metadata|Metadata[]|null
     */
    protected function getMetadataForClass(
        TransformerPayload $payload,
        MetadataRegistry $registry
    ) {
        // Outbound entities are easy to get Metadata for. Just as for it.
        if ($payload->getDirection() === TransformerPayload::OUTBOUND) {
            $entity = $payload->getValue();
            if (null === $entity) {
                return null;
            }
            $className = get_class($entity);

            return $registry->findMetadataByClass($className);
        } else {
            /* Inbound data is much harder. We have to work with what we know.
             * 1. We know what the target entity class is for the associated property/field on the current entity
             * we're building
             * 2. Using the Class Metadata from Doctrine for the target entity class, we can determine if we're dealing
             * with an inherited or mapped super class.
             * 3. With a valid connection, the Metadata from Salesforce will allow us to check the Key Prefix for an
             * Object Type in Salesforce against the Id that's been provided so we can better narrow down which
             * Metadata object best matches the given Salesforce ID
            */
            $id            = $payload->getValue();
            $classMetadata = $payload->getClassMetadata();
            $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
            $className     = $association['targetEntity'];
            /** @var EntityManager $assocManager */
            $assocManager       = $this->managerRegistry->getManagerForClass($className);
            $assocClassMetadata = $assocManager->getClassMetadata($className);
            $meta               = [];
            $subClasses         = [];

            // Check the root class to ensure the Metadata exists and is a fit for the ID provided
            if (null !== ($metadata = $registry->findMetadataByClass($className))) {
                if (null !== ($describe = $metadata->getDescribe()) && null !== ($prefix = $describe->getKeyPrefix())) {
                    if (substr($id, 0, strlen($prefix)) === $prefix) {
                        $meta[] = $metadata;
                    }
                } else {
                    $meta[] = $metadata;
                }
            }

            // Gather up the subclasses, if any
            if (!$assocClassMetadata->isInheritanceTypeNone()) {
                $subClasses = $assocClassMetadata->discriminatorMap;
            } elseif ($assocClassMetadata->isMappedSuperclass) {
                $subClasses = $assocClassMetadata->subClasses;
            }

            // Check the subclasses to ensure the Metadata exists and is a fit for the ID provided
            if (!empty($subClasses)) {
                foreach ($subClasses as $class) {
                    if (null !== ($metadata = $registry->findMetadataByClass($class))) {
                        if (null !== ($describe = $metadata->getDescribe())
                            && null !== ($prefix = $describe->getKeyPrefix())) {
                            if (substr($id, 0, strlen($prefix)) === $prefix) {
                                $meta[] = $metadata;
                            }
                        } else {
                            $meta[] = $metadata;
                        }
                    }
                }
            }

            return empty($meta) ? null : $meta;
        }
    }

    /**
     * @param TransformerPayload $payload
     */
    protected function transformInbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();

        try {
            $association = $classMetadata->getAssociationMapping($payload->getPropertyName());
            $className   = $association['targetEntity'];
            $meta        = $this->getMetadataForClass($payload, $connection->getMetadataRegistry());
        } catch (MappingException $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
            $payload->setValue(null);

            return;
        }

        if (null === $meta) {
            $payload->setValue(null);

            return;
        }

        /** @var EntityManager $manager */
        $manager = $this->managerRegistry->getManagerForClass($className);
        $repo    = $manager->getRepository($className);

        foreach ($meta as $metadata) {
            $sfidProperty = $metadata->getIdFieldProperty();

            // If the target entity doesn't have an SFID to lookup, can't locate the source
            if (null === $sfidProperty) {
                continue;
            }

            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($className);
            $value         = $payload->getValue();
            $entity        = null;
            if ($classMetadata->hasField($sfidProperty)) {
                $entity = $repo->findOneBy([$sfidProperty => $value]);
            } elseif ($classMetadata->hasAssociation($sfidProperty)) {
                $targetClass = $classMetadata->getAssociationTargetClass($sfidProperty);
                /** @var ClassMetadata $targetMetadata */
                $targetMetadata = $this->managerRegistry->getManagerForClass($targetClass)
                                                        ->getClassMetadata($targetClass)
                ;
                try {
                    $idField = $targetMetadata->getSingleIdentifierFieldName();
                } catch (MappingException $e) {
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());

                    continue;
                }
                $sfid = $this->sfidFinder->find($value, $targetClass);

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

            if (null === $entity) {
                continue;
            }

            $payload->setValue($entity);
        }

        // If the entity isn't found, we don't want to try and set a string value for it
        if (is_string($payload->getValue())) {
            $payload->setValue(null);
        }
    }

    /**
     * @param TransformerPayload $payload
     */
    protected function transformOutbound(TransformerPayload $payload)
    {
        $connection = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $entity     = $payload->getValue();
        $className  = get_class($entity);

        try {
            $metadata = $this->getMetadataForClass($payload, $connection->getMetadataRegistry());
        } catch (MappingException $e) {
            $this->logger->error($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
            $payload->setValue(null);

            return;
        }

        if (null === $metadata) {
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
