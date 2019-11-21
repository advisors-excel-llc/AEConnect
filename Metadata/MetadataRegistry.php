<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 1:54 PM
 */

namespace AE\ConnectBundle\Metadata;

use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;

/**
 * Class MetadataRegistry
 *
 * @package AE\ConnectBundle\Metadata
 */
class MetadataRegistry
{
    /**
     * @var ArrayCollection|Metadata[]
     */
    private $metadata;

    /** @var CacheProvider */
    private $cache;

    /**
     * MetadataRegistry constructor.
     *
     * @param CacheProvider $cache
     */
    public function __construct(CacheProvider $cache)
    {
        $this->metadata = new ArrayCollection();
        $this->cache    = $cache;
    }

    /**
     * @return ArrayCollection|Metadata[]
     */
    public function getSfidMetadata(): ArrayCollection
    {
        return $this->metadata->filter(function($metadatum) {
            return $metadatum->getClassAnnotation() == SalesforceId::class;
        });
    }

    /**
     * @return ArrayCollection|MetaData[]
     */
    public function getAllMetadata(): ArrayCollection
    {
        return $this->metadata;
    }

    /**
     * @return ArrayCollection|Metadata[]
     */
    public function getMetadata(): ArrayCollection
    {
        return $this->metadata->filter(function($metadatum) {
            return $metadatum->getClassAnnotation() == SObjectType::class;
        });
    }

    /**
     * @param ArrayCollection $metadata
     *
     * @return MetadataRegistry
     */
    public function setMetadata(ArrayCollection $metadata): MetadataRegistry
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param Metadata $metadata
     *
     * @return MetadataRegistry
     */
    public function addMetadata(Metadata $metadata): MetadataRegistry
    {
        $this->metadata->set($metadata->getClassName(), $metadata);

        return $this;
    }

    /**
     * @param Metadata $metadata
     *
     * @return MetadataRegistry
     */
    public function removeMetadata(Metadata $metadata): MetadataRegistry
    {
        $this->metadata->removeElement($metadata);

        return $this;
    }

    /**
     * @param string $sObjectType
     *
     * @return Metadata[]|array
     */
    public function findMetadataBySObjectType(string $sObjectType): array
    {
        $results = [];

        /** @var Metadata $metadatum */
        foreach ($this->metadata as $metadatum) {
            if ($metadatum->getSObjectType() === $sObjectType) {
                $results[] = $metadatum;
            }
        }

        return $results;
    }

    /**
     * @param SObject $object
     *
     * @return array|Metadata[]
     */
    public function findMetadataBySObject(SObject $object): array
    {
        if (null === $object->__SOBJECT_TYPE__) {
            $type = $object->getType();
            if ($type === null) {
                return [];
            }
            $object->__SOBJECT_TYPE__ = $type;
        }

        $metadata = $this->findMetadataBySObjectType($object->__SOBJECT_TYPE__);

        if (null !== $object->RecordTypeId) {
            $filtered = [];
            foreach ($metadata as $meta) {
                if (null === ($recordType = $meta->getRecordType())
                    || (
                        null === ($name = $recordType->getName())
                        || $meta->getRecordTypeId($name) === $object->RecordTypeId
                    )
                ) {
                    $filtered[] = $meta;
                }
            }

            return $filtered;
        }

        return $metadata;
    }

    /**
     * @param string $id
     *
     * @return array|Metadata[]
     */
    public function findMetadataBySObjectId(string $id)
    {
        $metadata = [];
        foreach ($this->metadata as $metadatum) {
            $describe = $metadatum->getDescribe();
            if (null === $describe) {
                continue;
            }

            $prefix = $describe->getKeyPrefix();

            if (null === $prefix) {
                continue;
            }

            if (strtolower(substr($id, 0, strlen($prefix))) === strtolower($prefix)) {
                $metadata[] = $metadatum;
            }
        }

        return $metadata;
    }

    /**
     * @param string $className
     *
     * @return Metadata|null
     */
    public function findMetadataByClass(string $className): ?Metadata
    {
        if (!isset($this->metadata[$className])) {
            $className = ClassUtils::getRealClass($className);
        }

        return $this->metadata->get($className);
    }

    /**
     * @param object $entity
     *
     * @return Metadata|null
     */
    public function findMetadataForEntity($entity): ?Metadata
    {
        $class = ClassUtils::getClass($entity);

        return $this->findMetadataByClass($class);
    }

    /**
     * @return CacheProvider
     */
    public function getCache(): CacheProvider
    {
        return $this->cache;
    }
}
