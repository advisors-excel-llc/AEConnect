<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/16/18
 * Time: 9:52 AM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use Doctrine\Common\Collections\ArrayCollection;

class DependencyNode implements \Countable
{
    /** @var CompilerResult|null */
    private $item;

    /** @var ArrayCollection */
    private $dependencies;

    /** @var DependencyNode|null */
    private $parent;

    public function __construct()
    {
        $this->dependencies = new ArrayCollection();
    }

    /**
     * @return CompilerResult|null
     */
    public function getItem(): ?CompilerResult
    {
        return $this->item;
    }

    /**
     * @param CompilerResult|null $item
     *
     * @return DependencyNode
     */
    public function setItem(?CompilerResult $item): DependencyNode
    {
        $this->item = $item;

        return $this;
    }

    /**
     * @return ArrayCollection
     */
    public function getDependencies(): ArrayCollection
    {
        return $this->dependencies;
    }

    /**
     * @param ArrayCollection $dependencies
     *
     * @return DependencyNode
     */
    public function setDependencies(ArrayCollection $dependencies): DependencyNode
    {
        $this->dependencies = $dependencies;

        return $this;
    }

    public function containsDependency(DependencyNode $group): bool
    {
        return $this->dependencies->contains($group);
    }

    public function addDependency(DependencyNode $group)
    {
        if (!$this->containsDependency($group)) {
            $this->dependencies->add($group);
            $group->setParent($this);
        }

        return $this;
    }

    public function removeDependency(DependencyNode $group)
    {
        if ($this->containsDependency($group)) {
            $this->dependencies->removeElement($group);
            $group->setParent(null);
        }

        return $this;
    }

    /**
     * @return DependencyNode|null
     */
    public function getParent(): ?DependencyNode
    {
        return $this->parent;
    }

    /**
     * @param DependencyNode|null $parent
     *
     * @return DependencyNode
     */
    public function setParent(?DependencyNode $parent): DependencyNode
    {
        if (null !== $this->parent && $parent !== $this->parent) {
            $this->parent->removeDependency($this);
        }

        $this->parent = $parent;

        if (null !== $parent && !$parent->containsDependency($this)) {
            $parent->addDependency($this);
        }

        return $this;
    }

    public function isParentOf(DependencyNode $node): bool
    {
        if (null === $node->getParent()) {
            return false;
        }

        if ($node->getParent() === $this) {
            return true;
        }

        if (null !== $node->getParent()) {
            return $this->isParentOf($node->getParent());
        }

        return false;
    }

    public function count()
    {
        $count = null === $this->item ? 0 : 1;

        foreach ($this->dependencies as $dependency) {
            $count += $dependency->count();
        }

        return $count;
    }
}
