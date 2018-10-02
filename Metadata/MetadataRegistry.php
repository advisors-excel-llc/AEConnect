<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 1:54 PM
 */

namespace AE\ConnectBundle\Metadata;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\ArrayCollection;

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
     * @return Metadata|null
     */
    public function findMetadataBySObjectType(string $sObjectType): ?Metadata
    {
        /** @var Metadata $metadatum */
        foreach ($this->metadata as $metadatum) {
            if ($metadatum->getSObjectType() === $sObjectType) {
                return $metadatum;
            }
        }

        return null;
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
     * @return CacheProvider
     */
    public function getCache(): CacheProvider
    {
        return $this->cache;
    }
}
