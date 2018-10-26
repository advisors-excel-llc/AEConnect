<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 9:47 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use Symfony\Bridge\Doctrine\RegistryInterface;

abstract class AbstractTreeBuilder
{
    /**
     * @var RegistryInterface
     */
    protected $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function build(ConnectionInterface $connection): array
    {
        $tree    = [];

        $objects = $this->aggregate($connection);

        $masterList = $objects;

        while (count($objects) > 0) {
            uasort(
                $objects,
                function ($a, $b) {
                    if (self::deepCount($a) > self::deepCount($b)) {
                        return 1;
                    }

                    if (self::deepCount($b) > self::deepCount($a)) {
                        return -1;
                    }

                    return 0;
                }
            );

            reset($objects);
            $type = key($objects);
            $diff  = $objects;
            unset($diff[$type]);

            $branch = [];
            static::findDependencies($type, $diff, $branch);
            $branch = static::cleanDependencies(array_keys($branch), $masterList);
            foreach (array_keys($branch) as $className) {
                static::removeBranch($className, $tree);
            }
            if (!static::addBranch($type, $branch, $tree)) {
                $tree[$type] = $branch;
            }
            static::removeBranch($type, $objects);
        }

        return $tree;
    }

    public function buildFlatMap(ConnectionInterface $connection): array
    {
        $map = [];
        $tree = $this->build($connection);

        static::flatten($tree, $map);

        return $map;
    }

    abstract protected function aggregate(ConnectionInterface $connection): array;

    protected static function deepCount(array $arr)
    {
        $count = count($arr);

        foreach ($arr as $darr) {
            $count += static::deepCount($darr);
        }

        return $count;
    }

    protected static function flatten($tree, &$map)
    {
        foreach ($tree as $key => &$branch) {
            $map[] = $key;
            static::flatten($branch, $map);
        }
    }

    abstract protected function buildDependencies(MetadataRegistry $metadataRegistry, Metadata $metadata): array;

    private static function findDependencies($needle, array $haystack, array &$found)
    {
        if (in_array($needle, array_keys($haystack))) {
            return true;
        }

        foreach ($haystack as $key => $value) {
            if (true === static::findDependencies($needle, $value, $found)) {
                $found[$key] = [];
            }
        }

        return false;
    }

    protected static function cleanDependencies(array $deps, array $classes)
    {
        $cleaned = $deps;

        foreach ($deps as $dep) {
            foreach ($deps as $index => $oDep) {
                if ($oDep === $dep) {
                    continue;
                }

                if (static::hasDependency($dep, $classes[$oDep])) {
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

    protected static function hasDependency($class, array $classes)
    {
        if (array_key_exists($class, $classes)) {
            return true;
        }

        foreach ($classes as $childClass) {
            if (static::hasDependency($class, $childClass)) {
                return true;
            }
        }

        return false;
    }

    protected static function removeBranch($className, array &$tree)
    {
        foreach ($tree as $class => &$branch) {
            if ($class === $className) {
                unset($tree[$class]);

                static::removeBranch($className, $tree);
            }

            if (is_array($branch)) {
                static::removeBranch($className, $branch);
            }
        }
    }

    protected static function addBranch($class, array $branch, array &$tree)
    {
        foreach ($tree as $key => &$value) {
            if ($key === $class) {
                $tree[$class] = $branch;

                return true;
            }

            if (static::addBranch($class, $branch, $value)) {
                return true;
            }
        }

        return false;
    }
}
