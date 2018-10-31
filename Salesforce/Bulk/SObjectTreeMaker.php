<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 12:13 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;

/**
 * Class SObjectTreeMaker
 *
 * @package AE\ConnectBundle\Salesforce\Bulk
 */
class SObjectTreeMaker extends AbstractTreeBuilder
{
    /**
     * @param ConnectionInterface $connection
     *
     * @return array
     */
    protected function aggregate(ConnectionInterface $connection): array
    {
        $objects = [];

        foreach ($connection->getMetadataRegistry()->getMetadata() as $metadata) {
            $type = $metadata->getSObjectType();
            array_key_exists($type, $objects) ? $objects[$type] : ($objects[$type] = []);
            $objects[$type] = array_merge_recursive(
                $objects[$type],
                $this->buildDependencies($connection->getMetadataRegistry(), $metadata)
            );
        }

        return $objects;
    }

    /**
     * @param MetadataRegistry $metadataRegistry
     * @param Metadata $metadata
     *
     * @return array
     */
    protected function buildDependencies(MetadataRegistry $metadataRegistry, Metadata $metadata): array
    {
        $deps          = [];
        $class         = $metadata->getClassName();
        $classMetadata = $this->registry->getManagerForClass($class)->getClassMetadata($class);
        $fields        = array_keys($metadata->getPropertyMap());

        foreach ($fields as $field) {
            if ($classMetadata->isSingleValuedAssociation($field)) {
                $depClass    = $classMetadata->getAssociationTargetClass($field);
                $depMetadata = $metadataRegistry->findMetadataByClass($depClass);

                if (null !== $depMetadata) {
                    $depType        = $depMetadata->getSObjectType();

                    // self-referencing fields cause redundancy errors
                    if ($depType === $metadata->getSObjectType()) {
                        continue;
                    }

                    $deps[$depType] = array_merge_recursive(
                        array_key_exists($depType, $deps)
                            ? $deps[$depType]
                            : [],
                        $this->buildDependencies($metadataRegistry, $depMetadata)
                    );
                }
            }
        }

        return $deps;
    }
}
