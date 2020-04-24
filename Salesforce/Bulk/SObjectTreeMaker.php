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
 * Class SObjectTreeMaker.
 */
class SObjectTreeMaker extends AbstractTreeBuilder
{
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

    protected function buildDependencies(MetadataRegistry $metadataRegistry, Metadata $metadata): array
    {
        $deps = [];
        $class = $metadata->getClassName();
        $classMetadata = $this->registry->getManagerForClass($class)->getClassMetadata($class);
        $fields = array_keys($metadata->getPropertyMap());

        foreach ($fields as $field) {
            if ($classMetadata->isSingleValuedAssociation($field)) {
                $depClass = $classMetadata->getAssociationTargetClass($field);
                $depMetadatas = $metadataRegistry->findMetadataByClass($depClass);

                if (null === $depMetadatas) {
                    $subclassMetadata = $this->registry->getManagerForClass($depClass)->getClassMetadata($depClass);
                    if (is_array($subclassMetadata->subClasses)) {
                        foreach ($subclassMetadata->subClasses as $subClass) {
                            $depMetadatas[] = $metadataRegistry->findMetadataByClass($subClass);
                        }
                    }
                } else {
                    $depMetadatas = [$depMetadatas];
                }

                foreach ($depMetadatas as $depMetadata) {
                    if (null !== $depMetadata) {
                        $depType = $depMetadata->getSObjectType();

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
        }

        return $deps;
    }
}
