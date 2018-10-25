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
use Symfony\Bridge\Doctrine\RegistryInterface;

class EntityTreeMaker
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function build(ConnectionInterface $connection)
    {
        $classes = [];
        $tree    = [];

        foreach ($connection->getMetadataRegistry()->getMetadata() as $metadata) {
            $class = $metadata->getClassName();
            array_key_exists($class, $classes) ? $classes[$class] : ($classes[$class] = 0);
            $classes[$class] = $this->buildDeps($connection->getMetadataRegistry(), $metadata);
        }

        $deepCount = function (array $arr) use (&$deepCount) {
            $count = count($arr);

            foreach ($arr as $darr) {
                $count += $deepCount($darr);
            }

            return $count;
        };

        $masterList = $classes;

        while (count($classes) > 0) {
            uasort(
                $classes,
                function ($a, $b) use ($deepCount) {
                    if ($deepCount($a) > $deepCount($b)) {
                        return 1;
                    }

                    if ($deepCount($b) > $deepCount($a)) {
                        return -1;
                    }

                    return 0;
                }
            );

            reset($classes);
            $class = key($classes);
            $diff  = $classes;
            unset($diff[$class]);

            $branch = [];
            $this->findClasses($class, $diff, $branch);
            $branch = $this->cleanDeps(array_keys($branch), $masterList);
            foreach (array_keys($branch) as $className) {
                $this->removeBranch($className, $tree);
            }
            if (!$this->addBranch($class, $branch, $tree)) {
                $tree[$class] = $branch;
            }
            $this->removeBranch($class, $classes);
        }

        return $tree;
    }

    private function buildDeps(MetadataRegistry $metadataRegistry, Metadata $metadata)
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
                    $deps[$depClass] = $this->buildDeps($metadataRegistry, $depMetadata);
                }
            }
        }

        return $deps;
    }

    private function findClasses($needle, array $haystack, array &$found)
    {
        if (in_array($needle, array_keys($haystack))) {
            return true;
        }

        foreach ($haystack as $key => $value) {
            if (true === $this->findClasses($needle, $value, $found)) {
                $found[$key] = [];
            }
        }

        return false;
    }

    private function cleanDeps(array $deps, array $classes)
    {
        $cleaned = $deps;

        foreach ($deps as $dep) {
            foreach ($deps as $index => $oDep) {
                if ($oDep === $dep) {
                    continue;
                }

                if ($this->hasDependency($dep, $classes[$oDep])) {
                    $index = array_search($oDep, $cleaned);
                    if (false !== $index) {
                        unset($cleaned[$index]);
                    }
                }
            }
        }

        return array_map(
            function () {
                return [];
            },
            array_flip($cleaned)
        );
    }

    private function hasDependency($class, array $classes)
    {
        if (array_key_exists($class, $classes)) {
            return true;
        }

        foreach ($classes as $childClass) {
            if ($this->hasDependency($class, $childClass)) {
                return true;
            }
        }

        return false;
    }

    private function removeBranch($className, array &$tree)
    {
        foreach ($tree as $class => &$branch) {
            if ($class === $className) {
                unset($tree[$class]);

                $this->removeBranch($className, $tree);
            }

            if (is_array($branch)) {
                $this->removeBranch($className, $branch);
            }
        }
    }

    private function addBranch($class, array $branch, array &$tree)
    {
        foreach ($tree as $key => &$value) {
            if ($key === $class) {
                $tree[$class] = $branch;

                return true;
            }

            if ($this->addBranch($class, $branch, $value)) {
                return true;
            }
        }

        return false;
    }
}
