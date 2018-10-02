<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 10:42 AM
 */

namespace AE\ConnectBundle\Metadata;

use AE\ConnectBundle\Driver\AnnotationDriver;

class MetadataRegistryFactory
{
    /**
     * @param AnnotationDriver $driver
     * @param string $connectionName
     *
     * @return MetadataRegistry
     * @throws \ReflectionException
     */
    public static function generate(AnnotationDriver $driver, string $connectionName): MetadataRegistry
    {
        $registry = new MetadataRegistry();
        // TODO, retrieve from cache

        $classes = $driver->getAllClassNames();

        if (null !== $classes) {
            foreach ($driver->getAllClassNames() as $className) {
                $metadata = new Metadata($connectionName);

                $driver->loadMetadataForClass($className, $metadata);

                $registry->addMetadata($metadata);
            }
        }

        return $registry;
    }
}
