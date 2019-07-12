<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 1:54 PM
 */

namespace AE\ConnectBundle\Metadata;

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
     * @var ArrayCollection<string, Metadata>
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
    public function getMetadata(): ArrayCollection
    {
        return $this->metadata;
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
            return [];
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
     * @param string $className
     *
     * @return Metadata|null
     */
    public function findMetadataByClass(string $className): ?Metadata
    {
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
