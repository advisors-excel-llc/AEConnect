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
use PhpCollection\SortableInterface;
use Ramsey\Uuid\Uuid;

class ItemizedCollection implements Collection, SortableInterface
{
    /**
     * @var array<string, array>
     */
    private $elements = [];

    /**
     * @var string
     */
    private $currentItem = null;

    public function __construct(?array $elements = null)
    {
        if (null !== $elements) {
            foreach ($elements as $item => $element) {
                $this->add($element, $item);
            }
        }
    }

    protected function createFrom(array $elements)
    {
        return new static($elements);
    }

    protected function determineCurrentItem(): ?string
    {
        if (null === $this->currentItem) {
            reset($this->elements);
            $this->currentItem = key($this->elements);
            reset($this->elements[$this->currentItem]);
        }

        return $this->currentItem;
    }

    /**
     * @inheritDoc
     */
    public function add($element, ?string $item = null): self
    {
        if (null === $item) {
            $item = Uuid::uuid4()->toString();
        }

        if (!array_key_exists($item, $this->elements)) {
            $this->elements[$item] = [];
        }

        $this->elements[$item] = array_merge($this->elements[$item], is_array($element) ? $element : [$element]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clear()
    {
        $this->elements    = [];
        $this->currentItem = null;
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
    public function remove($key, ?string $item = null)
    {
        if (null === $item) {
            if (array_key_exists($key, $this->elements)) {
                unset($this->elements[$key]);
            } else {
                foreach ($this->elements as &$items) {
                    if (array_key_exists($key, $items)) {
                        unset($items[$key]);
                        break;
                    }
                }
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
        foreach ($this->elements as $item => &$set) {
            $index = array_search($element, $set, true);

            if (false !== $index) {
                unset($set[$index]);

                if (empty($set)) {
                    unset($this->elements[$item]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function containsKey($key, ?string $item = null)
    {
        if (null === $item) {
            if (array_key_exists($key, $this->elements)) {
                return true;
            }

            foreach ($this->elements as $items) {
                if (array_key_exists($key, $items)) {
                    return true;
                }
            }

            return false;
        }

        return array_key_exists($key, null === $item ? $this->elements : $this->elements[$item]);
    }

    /**
     * @inheritDoc
     */
    public function get($key, ?string $item = null)
    {
        if (!$this->containsKey($key, $item)) {
            return null;
        }

        if (null === $item) {
            if (array_key_exists($key, $this->elements)) {
                return $this->elements[$key];
            }

            foreach ($this->elements as $items) {
                if (array_key_exists($key, $items)) {
                    return $items[$key];
                }
            }
        }

        return array_key_exists($item, $this->elements) && array_key_exists($key, $this->elements[$item])
            ? $this->elements[$item][$key]
            : null;
    }

    /**
     * @inheritDoc
     */
    public function getKeys(?string $item = null)
    {
        return array_keys(null === $item ? $this->elements : $this->elements[$item]);
    }

    /**
     * @inheritDoc
     */
    public function getValues(?string $item = null)
    {
        if (null !== $item) {
            return array_values($this->elements[$item]);
        }

        $values = [];

        foreach ($this->elements as $set) {
            $values = array_merge($values, array_values($set));
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value, ?string $item = null)
    {
        if (null === $item) {
            $this->elements[$key] = (array)$value;
        } else {
            $this->elements[$item][$key] = $value;
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
    public function first(?string $item = null)
    {
        if (null === $item) {
            $values = reset($this->elements);
            if (false === $values) {
                return false;
            }
            $this->currentItem = key($this->elements);

            return reset($this->elements[$this->currentItem]);
        }

        if (!array_key_exists($item, $this->elements) || empty($this->elements[$item])) {
            return false;
        }

        $values            = &$this->elements[$item];
        $this->currentItem = $item;

        return reset($values);
    }

    /**
     * @inheritDoc
     */
    public function last(?string $item = null)
    {
        if (null === $item) {
            $values = end($this->elements);

            if (false === $values) {
                return false;
            }

            $this->currentItem = key($this->elements);

            return end($this->elements[$this->currentItem]);
        }

        if (!array_key_exists($item, $this->elements) || empty($this->elements[$item])) {
            return false;
        }

        $values            = &$this->elements[$item];
        $this->currentItem = $item;

        return end($values);
    }

    /**
     * @inheritDoc
     */
    public function key()
    {
        if (null === $this->determineCurrentItem()) {
            return null;
        }

        return key($this->elements[$this->currentItem]);
    }

    /**
     * @inheritDoc
     */
    public function current()
    {
        if (null === $this->determineCurrentItem()) {
            return null;
        }

        return current($this->elements[$this->currentItem]);
    }

    /**
     * @inheritDoc
     */
    public function next()
    {
        if (null === $this->determineCurrentItem()) {
            return null;
        }
        $next = next($this->elements[$this->currentItem]);

        if (false === $next) {
            $vNext = next($this->elements);
            if (false !== $vNext) {
                $this->currentItem = key($this->elements);
                $next              = reset($this->elements[$this->currentItem]);
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
    public function filter(Closure $p, $flags = 0)
    {
        $filtered = [];

        foreach ($this->elements as $item => $set) {
            $fset = array_filter($set, function () use ($p, $item) {
                $args = func_get_args();
                $args[] = $item;
                return call_user_func_array($p, $args);
            }, $flags);

            if (!empty($fset)) {
                $filtered[$item] = $fset;
            }
        }

        return $this->createFrom($filtered);
    }

    /**
     * @inheritDoc
     */
    public function forAll(Closure $p)
    {
        foreach ($this->elements as $item => $items) {
            foreach ($items as $key => $element) {
                if (false === $p($key, $element, $item)) {
                    return false;
                }
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

        foreach ($this->elements as $item => $set) {
            $elements[$item] = array_map(
                function ($element) use ($item, $func) {
                    $func($element, $item);
                },
                $set
            );
        }

        return $this->createFrom($elements);
    }

    /**
     * @param Closure $p
     *
     * @return array|ItemizedCollection[]
     */
    public function partition(Closure $p)
    {
        $matches   = [];
        $noMatches = [];

        foreach ($this->elements as $item => $set) {
            foreach ($set as $key => $element) {
                if ($p($key, $element, $item)) {
                    if (!array_key_exists($item, $matches)) {
                        $matches[$item] = [];
                    }
                    $matches[$item][$key] = $element;
                } else {
                    if (!array_key_exists($item, $noMatches)) {
                        $noMatches[$item] = [];
                    }
                    $noMatches[$item][$key] = $element;
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
     * @param int $offset
     * @param null $length
     * @param null $item
     *
     * @return ItemizedCollection
     */
    public function slice($offset, $length = null, $item = null): self
    {
        $index      = 0;
        $count      = 0;
        $collection = new static();

        if (null === $item
            ? $offset >= $this->count()
            : (!array_key_exists($item, $this->elements) || $offset >= count($this->elements[$item]))) {
            return $collection;
        }

        $elements = null === $item ? $this->elements : [$item => $this->elements[$item]];

        if ($offset < 0) {
            $offset = max(0, (null === $item ? $this->count() : count($this->elements[$item])) - 1 + $offset);
        }

        if ($length < 0) {
            $length = max(1, (null === $item ? $this->count() : count($this->elements[$item])) + $length);
        }

        foreach ($elements as $item => $set) {
            foreach ($set as $key => $value) {
                if ($index >= $offset && (null === $length || $count < $length)) {
                    $collection->set($key, $value, $item);
                    ++$count;
                } elseif (null !== $length && $count === $length) {
                    break;
                }
                ++$index;
            }

            if (null !== $length && $count === $length) {
                break;
            }
        }

        return $collection;
    }

    public function splice($offset, $length = null, $item = null): self
    {
        $slice = $this->slice($offset, $length, $item);

        while (($element = $slice->current())) {
            $this->removeElement($element);
            $slice->next();
        }

        return $slice;
    }

    public function reduce(Closure $closure, $initial = null)
    {
        $elements = $this->toArray();

        return array_reduce($elements, $closure, $initial);
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

    public function sort(int $sort_flags = SORT_ASC): bool
    {
        return ksort($this->elements, $sort_flags);
    }

    public function sortWith($callable)
    {
        uasort($this->elements, $callable);
    }
}
