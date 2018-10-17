<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/16/18
 * Time: 10:51 AM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class DependencyTreeBuilder
{
    /**
     * @param array|Collection|CompilerResult[] $items
     *
     * @return ArrayCollection
     */
    public static function build($items)
    {
        $nodes = self::buildNodes($items);

        return self::buildTree($nodes);
    }

    /**
     * @param $items
     *
     * @return DependencyNode
     */
    private static function buildNodes($items): DependencyNode
    {
        $dependencies = new DependencyNode();

        foreach ($items as $item) {
            $node = new DependencyNode();
            $node->setItem($item);
            $dependencies->addDependency($node);
        }

        return $dependencies;
    }

    /**
     * @param DependencyNode $dependencies
     *
     * @return ArrayCollection
     */
    private static function buildTree(DependencyNode $dependencies): ArrayCollection
    {
        foreach ($dependencies->getDependencies() as $dependency) {
            self::nest($dependency, $dependencies);
        }

        $ordered = self::order($dependencies);

        return new ArrayCollection($ordered);
    }

    /**
     * @param DependencyNode $node
     * @param DependencyNode $tree
     */
    private static function nest(DependencyNode $node, DependencyNode $tree)
    {
        $deps = self::findDependencies($node, $tree->getDependencies());

        /** @var DependencyNode $dep */
        foreach ($deps as $dep) {
            if (!$node->containsDependency($dep)) {
                $node->addDependency($dep);
                self::nest($dep, $tree);
            }
        }
    }

    private static function findDependencies(DependencyNode $node, ArrayCollection $dependencies): array
    {
        $refId = $node->getItem()->getReferenceId();
        $deps = [];

        /** @var DependencyNode $dependency */
        foreach ($dependencies as $dependency) {
            // Only worry with items that are not in the same branch as our current node
            if ($dependency->isParentOf($node)) {
                continue;
            }

            foreach ($dependency->getItem()->getSObject()->getFields() as $value) {
                if ($value instanceof ReferencePlaceholder && $value->getEntityRefId() === $refId) {
                    $deps[] = $dependency;
                }
            }

            if (!$dependency->getDependencies()->isEmpty()) {
                $deps = array_merge($deps, self::findDependencies($node, $dependency->getDependencies()));
            }
        }

        return $deps;
    }

    private static function order(DependencyNode $dependencies)
    {
        $arr = $dependencies->getDependencies()->toArray();

        usort($arr, function (DependencyNode $depA, DependencyNode $depB) {
            if ($depB->count() > $depA->count()) {
                return 1;
            }

            if ($depA->count() > $depB->count()) {
                return -1;
            }

            return 0;
        });

        return $arr;
    }
}
