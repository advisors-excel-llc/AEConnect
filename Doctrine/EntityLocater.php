<?php

namespace AE\ConnectBundle\Doctrine;

use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;

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
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var FieldCompiler
     */
    private $fieldCompiler;

    public function __construct(RegistryInterface $registry, FieldCompiler $fieldCompiler)
    {
        $this->registry      = $registry;
        $this->logger        = new NullLogger();
        $this->fieldCompiler = $fieldCompiler;
    }

    /**
     * @param SObject $object
     * @param Metadata $metadata
     *
     * @return mixed
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function locate(SObject $object, Metadata $metadata)
    {
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

                    if ($preCount > 0) {
                        $criteria->add($extIdCriteria);
                        foreach ($extIdProps as $prop => $value) {
                            $builder->setParameter($prop, $value);
                        }
                    }
                }
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
                        'The follow SFID value was rejected by the EntityCompiler: {val}',
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
