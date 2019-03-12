<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:34 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\SalesforceRestSdk\Model\SObject;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;

class BulkPreprocessor
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function preProcess(SObject $object, ConnectionInterface $connection)
    {
        $metadataRegistry = $connection->getMetadataRegistry();
        $values           = [];

        foreach ($metadataRegistry->findMetadataBySObjectType($object->__SOBJECT_TYPE__) as $metadata) {
            $class         = $metadata->getClassName();
            $manager       = $this->registry->getManagerForClass($class);
            $classMetadata = $manager->getClassMetadata($class);
            $ids           = [];

            foreach ($metadata->getIdentifyingFields() as $prop => $field) {
                $value = $object->$field;
                if (null !== $value && is_string($value) && strlen($value) > 0) {
                    if ($classMetadata->getTypeOfField($prop) instanceof UuidType) {
                        $value = Uuid::fromString($value);
                    }
                }
                $ids[$prop] = $value;
            }

            $entity = $manager->getRepository($class)->findOneBy($ids);

            // Found an entity, need pull off the identifying information from it, forget the rest
            if (null !== $entity) {
                foreach ($metadata->getPropertyMap() as $prop => $field) {
                    // Since we're not updating, we still want to update the ID
                    if ('id' === strtolower($field) || $metadata->isIdentifier($prop)) {
                        $values[$field] = $object->$field;
                    }
                }
            }
        }

        // If we have values to change, then we change them
        // If the object is new, $values will be empty and so we don't want to affect the incoming data
        if (!empty($values)) {
            $object->setFields($values);
        }
    }
}
