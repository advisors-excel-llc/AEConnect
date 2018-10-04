<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 10:51 AM
 */

namespace AE\ConnectBundle\Util;

use Closure;
use Doctrine\Common\Collections\Collection;

class PrioritizedCollection implements Collection, Prioritize
{
    /**
     * @var array<int, array>
     */
    private $elements = [];

    /**
     * @var int
     */
    private $currentPriority = null;

    public function __construct(?array $elements = null)
    {
        if (null !== $elements) {
            foreach ($elements as $priority => $element) {
                $this->add($element, $priority);
            }
        }
    }

    protected function createFrom(array $elements)
    {
        return new static($elements);
    }

    protected function determineCurrentPriority(): ?int
    {
        if (null === $this->currentPriority) {
            reset($this->elements);
            $this->currentPriority = key($this->elements);
        }

        return $this->currentPriority;
    }

    /**
     * @inheritDoc
     */
    public function add($element, int $item = 0): self
    {
        if (!array_key_exists($item, $this->elements)) {
            $this->elements[$item] = [];
        }

        $this->elements[$item][] = $element;

        ksort($this->elements, SORT_ASC);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clear()
    {
        $this->elements        = [];
        $this->currentPriority = null;
    }

    /**
     * @inheritDoc
     */
    public function contains($element)
    {
        return $this->indexOf($element) !== false;
    }

    /**
     * @inheritDoc
     */
    public function isEmpty()
    {
        return empty($this->elements);
    }

    /**
     * @inheritDoc
     */
    public function remove($key, ?int $item = null)
    {
        if (null === $item) {
            if (array_key_exists($key, $this->elements)) {
                unset($this->elements[$key]);
            }
        } elseif (array_key_exists($item, $this->elements) && array_key_exists($key, $this->elements[$item])) {
            unset($this->elements[$item][$key]);

            if (empty($this->elements[$item])) {
                unset($this->elements[$item]);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function removeElement($element)
    {
        foreach ($this->elements as $priority => &$set) {
            $index = array_search($element, $set, true);

            if (false !== $index) {
                unset($set[$index]);

                if (empty($set)) {
                    unset($this->elements[$priority]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function containsKey($key, ?int $item = null)
    {
        return array_key_exists($key, null === $item ? $this->elements : $this->elements[$item]);
    }

    /**
     * @inheritDoc
     */
    public function get($key, ?int $item = null)
    {
        return $this->containsKey($key, $item)
            ? (null === $item ? $this->elements[$key] : $this->elements[$item][$key])
            : null;
    }

    /**
     * @inheritDoc
     */
    public function getKeys(?int $item = null)
    {
        return array_keys(null === $item ? $this->elements : $this->elements[$item]);
    }

    /**
     * @inheritDoc
     */
    public function getValues(?int $item = null)
    {
        if (null !== $item) {
            return array_values($this->elements[$item]);
        }

        $values = [];

        foreach ($this->elements as $set) {
            $values = array_merge($values, $set);
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value, ?int $item = null)
    {
        if (null === $item) {
            $this->elements[$key] = (array)$value;
        } else {
            $this->elements[$item][$key] = $value;
            ksort($this->elements, SORT_ASC);
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray()
    {
        $values = [];

        foreach ($this->elements as $set) {
            $values = array_merge($values, $set);
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function first(?int $item = null)
    {
        if (null === $item) {
            $values = &reset($this->elements);
            if (false === $values) {
                return false;
            }
            $this->currentPriority = key($this->elements);
        } else {
            if (!array_key_exists($item, $this->elements) || empty($this->elements[$item])) {
                return false;
            }

            $values                = &$this->elements[$item];
            $this->currentPriority = $item;
        }

        return reset($values);
    }

    /**
     * @inheritDoc
     */
    public function last(?int $item = null)
    {
        if (null === $item) {
            $values = &end($this->elements);

            if (false === $values) {
                return false;
            }

            $this->currentPriority = key($this->elements);
        } else {
            if (!array_key_exists($item, $this->elements) || empty($this->elements[$item])) {
                return false;
            }

            $values                = &$this->elements[$item];
            $this->currentPriority = $item;
        }

        return end($values);
    }

    /**
     * @inheritDoc
     */
    public function key()
    {
        if (null === $this->determineCurrentPriority()) {
            return null;
        }
        return key($this->elements[$this->currentPriority]);
    }

    /**
     * @inheritDoc
     */
    public function current()
    {
        if (null === $this->determineCurrentPriority()) {
            return null;
        }
        return current($this->elements[$this->currentPriority]);
    }

    /**
     * @inheritDoc
     */
    public function next()
    {
        if (null === $this->determineCurrentPriority()) {
            return null;
        }
        $next = next($this->elements[$this->currentPriority]);

        if (false === $next) {
            $vNext = next($this->elements);
            if (false !== $vNext) {
                $this->currentPriority = key($this->elements);
                $next = rewind($this->elements[$this->currentPriority]);
            }
        }

        return $next;
    }

    /**
     * @inheritDoc
     */
    public function exists(Closure $p)
    {
        foreach ($this->elements as $set) {
            foreach ($set as $key => $element) {
                if ($p($key, $element)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function filter(Closure $p)
    {
        $filtered = [];

        foreach ($this->elements as $priority => $set) {
            $fset = array_filter($set, $p);

            if (!empty($fset)) {
                $filtered[$priority] = $fset;
            }
        }

        return $this->createFrom($filtered);
    }

    /**
     * @inheritDoc
     */
    public function forAll(Closure $p)
    {
        foreach ($this->toArray() as $key => $element) {
            if (!$p($key, $element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function map(Closure $func)
    {
        $elements = [];

        foreach ($this->elements as $priority => $set) {
            $elements[$priority] = array_map($func, $set);
        }

        return $this->createFrom($elements);
    }

    /**
     * @inheritDoc
     */
    public function partition(Closure $p)
    {
        $matches   = [];
        $noMatches = [];

        foreach ($this->elements as $priority => $set) {
            foreach ($set as $key => $element) {
                if ($p($key, $element)) {
                    if (!array_key_exists($priority, $matches)) {
                        $matches[$priority] = [];
                    }
                    $matches[$priority][$key] = $element;
                } else {
                    if (!array_key_exists($priority, $noMatches)) {
                        $noMatches[$priority] = [];
                    }
                    $noMatches[$priority][$key] = $element;
                }
            }
        }

        return [$this->createFrom($matches), $this->createFrom($noMatches)];
    }

    /**
     * @inheritDoc
     */
    public function indexOf($element)
    {
        foreach ($this->elements as $set) {
            $index = array_search($element, $set, true);

            if (false !== $index) {
                return $index;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function slice($offset, $length = null, ?int $priority = null)
    {
        if (null === $priority) {
            $array = $this->toArray();

            return array_slice($array, $offset, $length, true);
        }

        if (!array_key_exists($priority, $this->elements)) {
            return [];
        }

        return array_slice($this->elements[$priority], $offset, $length, true);
    }

    /**
     * @inheritDoc
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset)
    {
        if (is_array($offset) && count($offset) === 2) {
            return array_key_exists($offset[0], $this->elements)
                && array_key_exists($offset[1], $this->elements[$offset[0]]);
        } else {
            return array_key_exists($offset, $this->elements);
        }
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($offset)
    {
        if (is_array($offset) && count($offset) === 2) {
            $this->get($offset[1], $offset[0]);
        } else {
            $this->get($offset);
        }
    }

    /**
     * @inheritDoc
     */
    public function offsetSet($offset, $value)
    {
        if (is_array($offset) && count($offset) === 2) {
            $this->set($offset[1], $value, $offset[0]);
        } else {
            $this->set($offset, $value);
        }
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        if (is_array($offset) && count($offset) === 2) {
            $this->remove($offset[1], $offset[0]);
        } else {
            $this->remove($offset);
        }
    }

    /**
     * @inheritDoc
     */
    public function count()
    {
        $count = 0;

        foreach ($this->elements as $set) {
            $count += count($set);
        }

        return $count;
    }

    /**
     * @param $element
     *
     * @return int|null
     */
    public function priority($element): ?int
    {
        foreach ($this->elements as $priority => $set) {
            if (false !== array_search($element, $set, true)) {
                return $priority;
            }
        }

        return null;
    }

    public function prioritize($element, int $priority): self
    {
        if ($this->contains($element)) {
            $this->removeElement($element);
        }

        return $this->add($element, $priority);
    }
}
