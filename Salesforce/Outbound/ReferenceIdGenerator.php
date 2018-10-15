<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 1:29 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound;

use AE\ConnectBundle\Metadata\Metadata;
use Doctrine\Common\Util\ClassUtils;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ReferenceIdGenerator
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param $entity
     * @param Metadata $metadata
     *
     * @return null|string
     */
    public function create($entity, Metadata $metadata): ?string
    {
        $className = ClassUtils::getRealClass($metadata->getClassName());
        $manager = $this->registry->getManagerForClass($className);

        if (null === $manager) {
            throw new \RuntimeException("Unable to find a class manager for $className");
        }

        $fields = $metadata->getIdentifyingFields();

        if (empty($fields)) {
            return null;
        }

        $classMetadata = $manager->getMetadataFactory()->getMetadataFor($className);

        $refId = $metadata->getClassName();

        foreach (array_keys($fields) as $property) {
            $reflectionProperty = $classMetadata->getReflectionClass()->getProperty($property);
            $reflectionProperty->setAccessible(true);
            $refId              .= '|'.$reflectionProperty->getValue($entity);
        }

        return Uuid::uuid5(Uuid::NAMESPACE_X500, $refId);
    }
}
