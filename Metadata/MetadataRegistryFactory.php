<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 10:42 AM
 */

namespace AE\ConnectBundle\Metadata;

use AE\ConnectBundle\Driver\AnnotationDriver;
use Doctrine\Common\Cache\CacheProvider;

class MetadataRegistryFactory
{
    /**
     * @param AnnotationDriver $driver
     * @param CacheProvider $cache
     * @param string $connectionName
     *
     * @return MetadataRegistry
     * @throws \ReflectionException
     */
    public static function generate(
        AnnotationDriver $driver,
        CacheProvider $cache,
        string $connectionName
    ): MetadataRegistry {
        $registry = new MetadataRegistry($cache);
        $classes  = $driver->getAllClassNames();

        if (null !== $classes) {
            foreach ($driver->getAllClassNames() as $className) {
                $cacheId = "{$connectionName}__{$className}";
                if ($cache->contains($cacheId)) {
                    $metadata = $cache->fetch($cacheId);
                } else {
                    $metadata = new Metadata($connectionName);

                    $driver->loadMetadataForClass($className, $metadata);
                    // Don't save to cache here, there's something left to do (@see Connection::hydrateMetadataDescribe)
                }

                $registry->addMetadata($metadata);
            }
        }

        return $registry;
    }
}
