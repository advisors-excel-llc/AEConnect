<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:34 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\SalesforceRestSdk\Model\SObject;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;

class BulkPreprocessor
{
    /**
     * @var EntityLocater
     */
    private $entityLocater;

    public function __construct(EntityLocater $entityLocater)
    {
        $this->entityLocater = $entityLocater;
    }

    public function preProcess(SObject $object, ConnectionInterface $connection)
    {
        $metadataRegistry = $connection->getMetadataRegistry();
        $values           = [];

        foreach ($metadataRegistry->findMetadataBySObjectType($object->__SOBJECT_TYPE__) as $metadata) {
            try {
                $entity = $this->entityLocater->locate($object, $metadata);
            } catch (\Exception $e) {
                $entity = null;
            }

            // Found an entity, need pull off the identifying information from it, forget the rest
            if (null !== $entity) {
                foreach ($metadata->getPropertyMap() as $prop => $field) {
                    // Since we're not updating, we still want to update the ID
                    if ('id' === strtolower($field) || $metadata->isIdentifier($prop)) {
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
                        if (null !== $value) {
                            $values[$field] = $value;
                        }
                    }
                }
            }
        }

        // If we have values to change, then we change them
        // If the object is new, $values will be empty and so we don't want to affect the incoming data
        if (!empty($values)) {
            $values['__SOBJECT_TYPE__'] = $object->__SOBJECT_TYPE__;

            return new SObject($values);
        }

        return $object;
    }
}
