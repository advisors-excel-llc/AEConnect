<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 1:54 PM
 */

namespace AE\ConnectBundle\Metadata;

use Doctrine\Common\Collections\ArrayCollection;

class MetadataRegistry
{
    /**
     * @var ArrayCollection<string, Metadata>
     */
    private $metadata;

    public function __construct()
    {
        $this->metadata = new ArrayCollection();
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

    public function addMetadata(Metadata $metadata): MetadataRegistry
    {
        $this->metadata->set($metadata->getClassName(), $metadata);

        return $this;
    }

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
}
