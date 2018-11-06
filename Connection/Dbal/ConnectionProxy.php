<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 9:41 AM
 */

namespace AE\ConnectBundle\Connection\Dbal;

use AE\ConnectBundle\Metadata\MetadataRegistry;

class ConnectionProxy
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $config = [];

    /**
     * @var MetadataRegistry
     */
    private $metadataRegistry;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return ConnectionProxy
     */
    public function setName(string $name): ConnectionProxy
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array $config
     *
     * @return ConnectionProxy
     */
    public function setConfig(array $config): ConnectionProxy
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return MetadataRegistry
     */
    public function getMetadataRegistry(): MetadataRegistry
    {
        return $this->metadataRegistry;
    }

    /**
     * @param MetadataRegistry $metadataRegistry
     *
     * @return ConnectionProxy
     */
    public function setMetadataRegistry(MetadataRegistry $metadataRegistry): ConnectionProxy
    {
        $this->metadataRegistry = $metadataRegistry;
        $cache = $this->metadataRegistry->getCache();

        foreach ($this->metadataRegistry->getMetadata() as $metadata) {
            $cacheId = "{$this->name}__{$metadata->getClassName()}";

            if (!$cache->contains($cacheId)) {
                $cache->save($cacheId, $metadata);
            }
        }

        return $this;
    }
}
