<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/5/18
 * Time: 2:48 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Util\ItemizedCollection;
use Doctrine\Common\Collections\ArrayCollection;

class QueueGroup implements \Countable
{
    /**
     * @var ItemizedCollection
     */
    private $items;

    /**
     * @var ItemizedCollection
     */
    private $dependentUpdates;

    /**
     * @var ArrayCollection
     */
    private $childGroups;

    /**
     * @var QueueGroup
     */
    private $parentGroup;

    public function __construct(array $items = [], array $dependentUpdates = [])
    {
        $this->items            = new ItemizedCollection($items);
        $this->childGroups      = new ArrayCollection();
        $this->dependentUpdates = new ItemizedCollection($dependentUpdates);
    }

    /**
     * @return ItemizedCollection
     */
    public function getItems(): ItemizedCollection
    {
        return $this->items;
    }

    /**
     * @param ItemizedCollection $items
     *
     * @return QueueGroup
     */
    public function setItems(ItemizedCollection $items): QueueGroup
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @return ItemizedCollection
     */
    public function getDependentUpdates(): ItemizedCollection
    {
        return $this->dependentUpdates;
    }

    /**
     * @param ItemizedCollection $dependentUpdates
     *
     * @return QueueGroup
     */
    public function setDependentUpdates(ItemizedCollection $dependentUpdates): QueueGroup
    {
        $this->dependentUpdates = $dependentUpdates;

        return $this;
    }

    /**
     * @return ArrayCollection
     */
    public function getChildGroups(): ArrayCollection
    {
        return $this->childGroups;
    }

    /**
     * @param ArrayCollection $childGroups
     *
     * @return QueueGroup
     */
    public function setChildGroups(ArrayCollection $childGroups): QueueGroup
    {
        $this->childGroups = $childGroups;

        return $this;
    }

    public function containsChildGroup(QueueGroup $group): bool
    {
        return $this->childGroups->contains($group);
    }

    public function addChildGroup(QueueGroup $group): QueueGroup
    {
        if (!$this->childGroups->contains($group)) {
            if (null !== $group->getParentGroup()
                && $this !== $group->getParentGroup()
                && $group->getParentGroup()->containsChildGroup($group)
            ) {
                $group->getParentGroup()->removeChildGroup($group);
            }
            $this->childGroups->add($group);
            $group->setParentGroup($this);
        }

        return $this;
    }

    public function removeChildGroup(QueueGroup $group): QueueGroup
    {
        $this->childGroups->removeElement($group);
        $group->setParentGroup(null);

        return $this;
    }

    /**
     * @return QueueGroup
     */
    public function getParentGroup(): ?QueueGroup
    {
        return $this->parentGroup;
    }

    /**
     * @param QueueGroup $parentGroup
     *
     * @return QueueGroup
     */
    public function setParentGroup(?QueueGroup $parentGroup): QueueGroup
    {
        $this->parentGroup = $parentGroup;

        if (null !== $parentGroup && !$parentGroup->containsChildGroup($this)) {
            $parentGroup->addChildGroup($this);
        }

        return $this;
    }

    /**
     * Returns the total number of subrequests for the group and its dependencies
     *
     * @return int
     */
    public function count()
    {
        $count = 0;

        $count += $this->items->count();
        $count += $this->dependentUpdates->count();

        /** @var QueueGroup $group */
        foreach ($this->childGroups as $group) {
            $count += $group->count();
        }

        return $count;
    }

    public function groupCount()
    {
        $count = $this->items->isEmpty() ? 0 : 1;

        /** @var QueueGroup $group */
        foreach ($this->childGroups as $group) {
            $count += $group->groupCount();
        }

        return $count;
    }
}
