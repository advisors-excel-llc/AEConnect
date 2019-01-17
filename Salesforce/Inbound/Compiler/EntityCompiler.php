<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EntityCompiler
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var Transformer
     */
    private $transformer;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        Transformer $transformer,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->transformer       = $transformer;
        $this->validator         = $validator;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param SObject $object
     * @param string $connectionName
     *
     * @return array
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function compile(SObject $object, string $connectionName = 'default'): array
    {
        $connection = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            throw new \RuntimeException("Connection '$connectionName' could not be found.");
        }

        $entities = [];
        $metas    = $connection->getMetadataRegistry()->findMetadataBySObjectType($object->__SOBJECT_TYPE__);

        foreach ($metas as $metadata) {
            $class   = $metadata->getClassName();
            $manager = $this->registry->getManagerForClass($class);
            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($class);
            $entity        = null;

            try {
                $entity = $this->findExistingEntity($object, $metadata, $classMetadata);
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());
            }

            $connectionProp = $metadata->getConnectionNameField();

            if (null !== $entity) {
                // Check if the entity is meant for this connection
                if (null !== $connectionProp
                    && !$this->hasConnection(
                        $entity,
                        $connection,
                        $metadata
                    )
                ) {
                    $this->logger->debug(
                        "Entity {type} with Id {id} and meant for {conn}",
                        [
                            'type' => $class,
                            'id'   => $classMetadata->getFieldValue(
                                $entity,
                                $classMetadata->getSingleIdentifierFieldName()
                            ),
                            'conn' => $connection->getName(),
                        ]
                    );
                    continue;
                }
            } else {
                $entity = new $class();

                // If the entity supports a connection name, set it
                if (null !== $connectionProp) {
                    $connectionProp->setValueForEntity(
                        $entity,
                        $this->compileField(
                            '',
                            $connection->getName(),
                            $object,
                            $classMetadata,
                            $metadata,
                            $metadata->getConnectionNameField()
                        )
                    );
                }
            }

            foreach ($object->getFields() as $field => $value) {
                $fieldMetadata = $metadata->getMetadataForField($field);
                if (null === $fieldMetadata) {
                    continue;
                }

                $newValue = $this->compileField($field, $value, $object, $classMetadata, $metadata, $fieldMetadata);

                if (null !== $newValue) {
                    $fieldMetadata->setValueForEntity($entity, $newValue);
                }
            }

            try {
                $recordType = $metadata->getRecordType();

                if (null !== $recordType
                    && null !== $object->RecordTypeId
                    && null !== ($recordTypeName = $metadata->getRecordTypeDeveloperName($object->RecordTypeId))
                    && $recordType->getValueFromEntity($entity) !== $recordTypeName
                ) {
                    throw new \RuntimeException(
                        sprintf(
                            "The record type given, %s, does not match that of the entity, %s.",
                            $recordTypeName,
                            $recordType->getValueFromEntity($entity)
                        )
                    );
                }

                $this->validate($entity, $connection);

                $entities[] = $entity;
            } catch (\RuntimeException $e) {
                $manager->detach($entity);

                $this->logger->alert($e->getMessage());
                $this->logger->debug($e->getTraceAsString());
            }
        }

        return $entities;
    }

    /**
     * @param $entity
     * @param ConnectionInterface $connection
     */
    private function validate($entity, ConnectionInterface $connection)
    {
        $groups = ['ae_connect_inbound', 'ae_connect_inbound.'.$connection->getName()];

        if ($connection->isDefault() && 'default' !== $connection->getName()) {
            $groups[] = 'ae_connect_inbound.default';
        }

        $messages = $this->validator->validate(
            $entity,
            null,
            $groups
        );
        if (count($messages) > 0) {
            $err = '';
            foreach ($messages as $message) {
                $err .= $message.PHP_EOL;
            }

            throw new \RuntimeException(
                "The entity does not meet the following validations:".PHP_EOL.$err
            );
        }
    }

    /**
     * @param $entity
     * @param ConnectionInterface $connection
     * @param Metadata $metadata
     *
     * @return bool
     */
    private function hasConnection($entity, ConnectionInterface $connection, Metadata $metadata): bool
    {
        $connectionProp = $metadata->getConnectionNameField();
        $conn           = $connectionProp->getValueFromEntity($entity);

        if (null === $conn) {
            return false;
        }

        if (is_string($conn)) {
            return $conn === $connection->getName();
        }

        if ($conn instanceof ConnectionEntityInterface) {
            return $conn->getName() === $connection->getName();
        }

        if (is_array($conn) || $conn instanceof \ArrayAccess) {
            foreach ($conn as $value) {
                if ($value instanceof ConnectionEntityInterface && $value->getName() === $connection->getName()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param SObject $object
     * @param Metadata $metadata
     * @param ClassMetadata $classMetadata
     *
     * @return mixed
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \RuntimeException
     */
    private function findExistingEntity(SObject $object, Metadata $metadata, ClassMetadata $classMetadata)
    {
        /** @var EntityRepository $repo */
        $repo        = $this->registry->getRepository($classMetadata->getName());
        $builder     = $repo->createQueryBuilder('o');
        $identifiers = $metadata->getIdentifyingFields();
        $criteria    = $builder->expr()->andX();

        // External Identifiers are the best way to lookup an entity. Even if the SFID is wrong but the
        // External Id matches, then we want to use the entity with this External ID
        foreach ($identifiers as $prop => $field) {
            $value = $object->$field;
            if (null !== $value && is_string($value) && strlen($value) > 0) {
                if ($classMetadata->getTypeOfField($field) instanceof UuidType) {
                    $value = Uuid::fromString($value);
                }
                $criteria->add($builder->expr()->eq("o.$prop", ":$prop"));
                $builder->setParameter($prop, $value);
            }
        }

        // Only add to the builder if there is criteria to add
        if ($criteria->count() > 0) {
            $builder->where($criteria);
        }

        // If there's an SFID, use it to find the entity, in case the External Id isn't found in the database or one
        // wasn't provided from Salesforce
        if (null !== $object->Id && strlen($object->Id) > 0 && null !== ($property = $metadata->getIdFieldProperty())) {
            $sfidVal = $this->compileField(
                'Id',
                $object->Id,
                $object,
                $classMetadata,
                $metadata,
                $metadata->getMetadataForField('Id')
            );

            if ($sfidVal instanceof Collection) {
                $sfidVal = $sfidVal->first();
            } elseif (is_array($sfidVal)) {
                $sfidVal = array_shift($sfidVal);
            }

            if ($classMetadata->hasAssociation($property)) {
                $targetClass = $classMetadata->getAssociationTargetClass($property);
                /** @var ClassMetadata $targetMetadata */
                $targetMetadata = $this->registry->getManagerForClass($targetClass)->getClassMetadata($targetClass);
                $idField        = $targetMetadata->getSingleIdentifierFieldName();

                // Reduce associated entities to just their ID value for lookup
                if (null !== $sfidVal && get_class($sfidVal) === $targetClass) {
                    $value = $targetMetadata->getFieldValue($sfidVal, $idField);
                    if (null !== $value) {
                        $builder->join("o.$property", 's');
                        $builder->orWhere($builder->expr()->eq("s.id", ":$property"));
                        $builder->setParameter($property, $value);
                    }
                }
            } else {
                $builder->orWhere($builder->expr()->eq("o.$property", ":$property"));
                $builder->setParameter($property, $sfidVal);
            }
        }

        if ($builder->getParameters()->isEmpty()) {
            throw new \RuntimeException("No restricting parameters found");
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * @param $field
     * @param $value
     * @param SObject $object
     * @param $classMetadata
     * @param $metadata
     * @param $fieldMetadata
     *
     * @return mixed
     */
    private function compileField(
        ?string $field,
        $value,
        SObject $object,
        ClassMetadata $classMetadata,
        Metadata $metadata,
        FieldMetadata $fieldMetadata
    ) {
        $payload = TransformerPayload::inbound()
                                     ->setClassMetadata($classMetadata)
                                     ->setEntity($object)
                                     ->setMetadata($metadata)
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setFieldName($field)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setValue(is_string($value) && strlen($value) === 0 ? null : $value)
        ;

        $this->transformer->transform($payload);

        return $payload->getValue();
    }
}
