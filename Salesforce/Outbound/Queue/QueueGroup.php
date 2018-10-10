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
    private $dependentGroups;

    public function __construct(array $items = [], array $dependentUpdates = [])
    {
        $this->items            = new ItemizedCollection($items);
        $this->dependentGroups  = new ArrayCollection();
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
    public function getDependentGroups(): ArrayCollection
    {
        return $this->dependentGroups;
    }

    /**
     * @param ArrayCollection $dependentGroups
     *
     * @return QueueGroup
     */
    public function setDependentGroups(ArrayCollection $dependentGroups): QueueGroup
    {
        $this->dependentGroups = $dependentGroups;

        return $this;
    }

    public function addDependentGroup(QueueGroup $group): QueueGroup
    {
        if (!$this->dependentGroups->contains($group)) {
            $this->dependentGroups->add($group);
        }

        return $this;
    }

    public function removeDependentGroup(QueueGroup $group): QueueGroup
    {
        $this->dependentGroups->removeElement($group);

        return $this;
    }

    /**
     * Returns the total number of subrequests for the group and its dependencies
     * @return int
     */
    public function count()
    {
        $count = 0;

        $count += $this->items->count();
        $count += $this->dependentUpdates->count();

        /** @var QueueGroup $group */
        foreach ($this->dependentGroups as $group) {
            $count += $group->count();
        }

        return $count;
    }
}
