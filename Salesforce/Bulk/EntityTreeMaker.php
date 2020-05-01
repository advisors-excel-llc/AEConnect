<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 12:13 PM.
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;

/**
 * Class EntityTreeMaker.
 */
class EntityTreeMaker extends AbstractTreeBuilder
{
    protected function aggregate(ConnectionInterface $connection): array
    {
        $classes = [];

        foreach ($connection->getMetadataRegistry()->getMetadata() as $metadata) {
            $class = $metadata->getClassName();
            array_key_exists($class, $classes) ? $classes[$class] : ($classes[$class] = 0);
            $classes[$class] = $this->buildDependencies($connection->getMetadataRegistry(), $metadata);
        }

        return $classes;
    }

    protected function buildDependencies(MetadataRegistry $metadataRegistry, Metadata $metadata): array
    {
        $deps = [];
        $class = $metadata->getClassName();
        $classMetadata = $this->registry->getManagerForClass($class)->getClassMetadata($class);
        $fields = array_keys($metadata->getPropertyMap());

        foreach ($fields as $field) {
            if ($classMetadata->isSingleValuedAssociation($field)) {
                $depClass = $classMetadata->getAssociationTargetClass($field);

                // Self-referencing fields cause redundancy errors
                if ($depClass === $class || in_array($depClass, class_parents($class))) {
                    continue;
                }

                $depMetadata = $metadataRegistry->findMetadataByClass($depClass);

                if (null !== $depMetadata) {
                    $deps[$depClass] = $this->buildDependencies($metadataRegistry, $depMetadata);
                }
            }
        }

        return $deps;
    }
}
