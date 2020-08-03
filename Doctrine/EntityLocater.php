<?php

namespace AE\ConnectBundle\Doctrine;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\LocationQuery;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/2/19
 * Time: 12:27 PM
 */
class EntityLocater implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var FieldCompiler
     */
    private $fieldCompiler;

    private $cachedDQL = [];

    public function __construct(ManagerRegistry $registry, FieldCompiler $fieldCompiler)
    {
        $this->logger->info('EntityLocater->__construct');
        $this->registry      = $registry;
        $this->logger        = new NullLogger();
        $this->fieldCompiler = $fieldCompiler;
    }

    public function locateEntities(Target $target, ConnectionInterface $connection)
    {
        $this->logger->info('EntityLocater->locateEntities');
        if (!count($target->getLocators()) && !$target->isQBImpossible()) {
            $this->constructLocatorsForGivenTarget($target, $connection);
        }
        if ($target->isQBImpossible()) {
            //If we failed to build a QB from meta data, we will use the slow motion constructor instead.
            foreach($target->records as $record) {
                foreach ($connection->getMetadataRegistry()->findMetadataBySObjectType($target->name) as $metadata) {
                    $record->entity =  $this->locate($record->sObject, $metadata);
                }
            }
        } else {
            // We are ready
            $target->executeLocators();
        }
    }

    /**
     * Given an entity class name and a set of SFIDs, we need to return an array of matched IDs off the entities table
     * @param string $class
     * @param array $sfids
     * @param ConnectionInterface $connection
     * @return mixed - an array of arrays with the shape class => [$id, $sfid]
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function locateEntitiesBySFID(string $class, array $sfids, ConnectionInterface $connection)
    {
        $this->logger->info('EntityLocater->locateEntitiesBySFID');
        //If we haven't seen this class before, we need to construct a query for it to use.
        if (!isset($this->cachedDQL[$class])) {
            /** @var EntityManager $manager */
            $manager        = $this->registry->getManagerForClass($class);
            $entityMetaData = $manager->getClassMetadata($class);

            $subClasses = [$class];

            if (count($entityMetaData->subClasses)) {
                $subClasses = $entityMetaData->subClasses;
            }

            foreach ($subClasses as $subClass) {
                $classMeta      = $connection->getMetadataRegistry()->findMetadataByClass($subClass);
                $repository     = $manager->getRepository($subClass);
                $sfidProperty   = $classMeta->getIdFieldProperty();

                $qb = $repository->createQueryBuilder('e');

                if ($entityMetaData->hasAssociation($sfidProperty)) {
                    $association             = $entityMetaData->getAssociationMapping($sfidProperty);
                    $targetAeConnectMetadata = $connection->getMetadataRegistry()->findMetadataByClass(
                        $association['targetEntity']
                    );
                    if ($targetAeConnectMetadata) {
                        $associationSFIDProperty = $targetAeConnectMetadata->getIdFieldProperty();
                        $qb->select('e.id, s.'.$associationSFIDProperty . ' as sfid')
                           ->leftJoin('e.'.$sfidProperty, 's')
                           ->where('s.'.$associationSFIDProperty.' IN (:sfids)');
                    }
                } elseif ($entityMetaData->hasField($sfidProperty)) {
                    $qb->select('e.id, e.'.$sfidProperty . ' as sfid')
                       ->where('e.'.$sfidProperty.' IN (:sfids)');
                }

                $this->cachedDQL[$class][$subClass] = $qb->getQuery();

            }
        }
        $results = [];
        foreach ($this->cachedDQL[$class] as $subClass => $query) {
            $results[$subClass] = $query->execute(['sfids' => $sfids]);
        }
        return $results;
    }

    /**
     * Locator Contruction.  We are going to examine the AEConnect meta data and create the instructions for creating a query
     * to retrieve a set of results from the database in a
     * @param Target $target
     * @param ConnectionInterface $connection
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function constructLocatorsForGivenTarget(Target $target, ConnectionInterface $connection)
    {
        $this->logger->info('EntityLocater->constructLocatorsForGivenTarget');
        foreach ($connection->getMetadataRegistry()->findMetadataBySObjectType($target->name) as $metadata) {
            // Lets build some DQL to do a query!
            /** @var EntityManager $manager */
            $manager = $this->registry->getManagerForClass($metadata->getClassName());
            $entityMetaData = $manager->getClassMetadata($metadata->getClassName());

            $locator = new LocationQuery();
            $locator->setRepository($this->registry, $metadata->getClassName());

            // Start with connection field.  If such a field exists we are going to require the connection field to
            // equal the connection name.
            if ($connField = $metadata->getConnectionNameField()) {
                // We are assuming here that connection field is a normal column and not an association.
                // If it is an association we are going to fix that later LOL pwned
                if ($entityMetaData->hasAssociation($connField)) {
                    $target->setQBImpossible(true, 'Since your connection field is an association, we are going to use slow motion entity locator instead of DQL to retrieve records.
                    This runs approximately 100x slower than DQL method.');
                    break;
                }
                if ($entityMetaData->hasField($connField)) {
                    $locator->addConnection($connField, $connection->getName());
                }
            }

            // Now for the ever reliable identifying fields.  We will need the identifying field to be in the records we got back.
            $identifiers   = $metadata->getIdentifyingFields();
            foreach ($identifiers as $prop => $field) {
                // We'll first ensure that our salesforce results will include this identifier by checking the query for the
                // inclusion of just this field
                if ($metadata->getActiveFieldMetadata()->exists(function($key, FieldMetadata $field) { return $field->getField() === $field; })) {
                    //Don't use the field if we didn't query the field...
                    continue;
                }
                // We'll once again assume here that all identifying fields are columns on a table instead of associations.
                // TODO : Write logic to make a fast lane for instances where identifying fields are associations.
                if ($entityMetaData->hasAssociation($prop)) {
                    $target->setQBImpossible(true, "Since one of your active identifying fields is an association ($prop), we are going to use slow motion entity locator instead of QB to retrieve records.
                    This runs approximately 100x slower than QB method.  You could potentially choose to remove this field from your query and run again if your SFIDs were not cleared.");
                    break;
                }
                if ($entityMetaData->hasField($prop)) {
                    $locator->addExternalId($prop, $field);
                }
            }

            //And lastly SFID.  This run we unfortunately have to support associations as well as fields, so get ready to see this
            if ($metadata->getActiveFieldMetadata()->exists(function($key, FieldMetadata $field) { return $field->getField() === 'Id'; })) {
                $sfidProperty = $metadata->getIdFieldProperty();
                if ($entityMetaData->hasAssociation($sfidProperty)) {
                    $association = $entityMetaData->getAssociationMapping($sfidProperty);
                    $targetAeConnectMetadata = $connection->getMetadataRegistry()->findMetadataByClass($association['targetEntity']);
                    if ($targetAeConnectMetadata) {
                        $locator->addSfidAssociation(
                            $association['targetEntity'],
                            $targetAeConnectMetadata->getIdFieldProperty(),
                            $sfidProperty
                        );
                    }
                } elseif ($entityMetaData->hasField($sfidProperty)) {
                    $locator->addSfidField($sfidProperty);
                }
            }
            if (!$locator->isOK()) {
                $target->setQBImpossible(true, 'Query did not contain an identifying field so we can not find them.');
            } else {
                $target->addLocator($locator);
            }
        }
    }

    /**
     * @param SObject $object
     * @param Metadata $metadata
     *
     * @return mixed
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function locate(SObject $object, Metadata $metadata)
    {
        $this->logger->info('EntityLocater->locate');
        $className = $metadata->getClassName();

        if (null === $className) {
            throw new \RuntimeException("No class set in the metadata");
        }

        $manager       = $this->registry->getManagerForClass($className);
        $classMetadata = $manager->getClassMetadata($className);
        /** @var EntityRepository $repo */
        $repo          = $manager->getRepository($className);
        $builder       = $repo->createQueryBuilder('o');
        $identifiers   = $metadata->getIdentifyingFields();
        $criteria      = $builder->expr()->andX();
        $extIdCriteria = $builder->expr()->andX();
        $sfidCriteria  = $builder->expr()->andX();
        $extIdProps    = [];
        $sfidProps     = [];

        // External Identifiers are the best way to lookup an entity. Even if the SFID is wrong but the
        // External Id matches, then we want to use the entity with this External ID
        foreach ($identifiers as $prop => $field) {
            $value = null;
            // This seems dumb to me, but it fixes the issue.
            // The $field should be exactly what comes from Salesforce because the metadata is populated
            // from Salesforce. But for some reason, the __get() on SObject doesn't seem to find it always
            // This is an issue in the SDK.
            foreach ($object->getFields() as $oField => $v) {
                if (strtolower($oField) === strtolower($field)) {
                    $value = $v;
                }
            }

            if (null !== $value && is_string($value) && strlen($value) > 0) {
                $typeOfField = $classMetadata->getTypeOfField($prop);

                if (is_string($typeOfField)) {
                    $typeOfField = Type::getType($typeOfField);
                }

                if ($typeOfField instanceof UuidType
                    || $typeOfField instanceof UuidBinaryType
                    || $typeOfField instanceof UuidBinaryOrderedTimeType
                ) {
                    $value = Uuid::fromString($value);
                }
                $extIdCriteria->add($builder->expr()->eq("o.$prop", ":$prop"));
                $extIdProps[$prop] = $value;
            }
        }

        // If the metadata uses a connection field, add that to the query properly
        $connField = $metadata->getConnectionNameField();
        $connId    = null;
        $conn      = null;
        if (null !== $connField) {
            $prop = $connField->getProperty();
            $conn = $this->fieldCompiler->compileInbound($metadata->getConnectionName(), $object, $connField);

            if ($conn instanceof Collection) {
                $conn = $conn->first();
            } elseif (is_array($conn)) {
                $conn = array_shift($conn);
            }

            // There's a connection field AND a value for the connection on the entity
            if (null !== $conn) {
                // The Connection field is an association, create a join to it
                if ($classMetadata->hasAssociation($prop)) {
                    $assoc       = $classMetadata->getAssociationMapping($prop);
                    $connClass   = $assoc['targetEntity'];
                    $connManager = $this->registry->getManagerForClass($connClass);
                    /** @var ClassMetadata $connMetadata */
                    $connMetadata = $connManager->getClassMetadata($connClass);
                    $connIdProp   = $connMetadata->getSingleIdentifierFieldName();
                    $connId       = $connMetadata->getFieldValue($conn, $connIdProp);

                    if (null !== $conn && null !== $connId) {
                        $builder->leftJoin("o.$prop", "c");
                        $sfidCriteria->add(
                            $builder->expr()->eq("c.$connIdProp", ":conn")
                        );
                        $sfidProps['conn'] = $connId;
                    }

                    if ($extIdCriteria->count() > 0) {
                        $criteria->add($extIdCriteria);
                        foreach ($extIdProps as $prop => $value) {
                            $builder->setParameter($prop, $value);
                        }
                    }
                } elseif ($classMetadata->hasField($prop)) {
                    $preCount = $extIdCriteria->count();
                    // The connection is a field, such aa a string value
                    $extIdCriteria->add($builder->expr()->eq("o.$prop", ":conn"));
                    $sfidCriteria->add($builder->expr()->eq("o.$prop", ":conn"));
                    $extIdProps['conn'] = $conn;
                    $sfidProps['conn']  = $conn;

                    // There must be at least one extId condition prior to adding the connection criteria
                    // This extId condition should be the external id by which to filter
                    if ($preCount > 0) {
                        $criteria->add($extIdCriteria);
                        foreach ($extIdProps as $prop => $value) {
                            $builder->setParameter($prop, $value);
                        }
                    }
                }
            }
        } elseif ($extIdCriteria->count() > 0) {
            // If there's no connection field, we still need to filter by external id
            $criteria->add($extIdCriteria);
            foreach ($extIdProps as $prop => $value) {
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
            $sfidVal = $this->fieldCompiler->compileInbound($object->Id, $object, $metadata->getMetadataForField('Id'));

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
                if (null !== $sfidVal && ((is_string($sfidVal) && $sfidVal === $targetClass)
                        || (is_object($sfidVal) && get_class($sfidVal) === $targetClass))
                ) {
                    $value = $targetMetadata->getFieldValue($sfidVal, $idField);
                    if (null !== $value) {
                        $sfidCriteria->add($builder->expr()->eq("s.id", ":$property"));
                        $sfidProps[$property] = $value;

                        $builder->leftJoin("o.$property", 's');
                    }
                } else {
                    $this->logger->warning(
                        'The follow SFID value was rejected by the EntityLocater: {val}',
                        [
                            'val' => $sfidVal,
                        ]
                    );
                }
            } else {
                $sfidCriteria->add($builder->expr()->eq("o.$property", ":$property"));
                $sfidProps[$property] = $sfidVal;
            }

            if ($sfidCriteria->count() > 0) {
                $builder->orWhere($sfidCriteria);

                foreach ($sfidProps as $prop => $value) {
                    $builder->setParameter($prop, $value);
                }
            }
        }

        if ($builder->getParameters()->isEmpty()) {
            throw new \RuntimeException("No restricting parameters found");
        }

        return $builder->getQuery()->getOneOrNullResult();
    }
}
