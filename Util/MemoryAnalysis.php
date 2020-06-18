<?php

namespace AE\ConnectBundle\Util;

class MemoryAnalysis
{
    private $name;
    private $queue;

    private $memoryStart;
    private $memoryEnd;

    private $totalChange = 0;
    private $last100Change = 0;
    private $iterations = 0;

    private $sObjects = [];

    public function __construct(?string $name = '')
    {
        $this->queue = new \SplQueue();
        $this->memoryStart = memory_get_usage();
        $this->name = $name;
    }

    public function _setStartMemory($memory)
    {
        $this->memoryStart = $memory;
    }

    public function next(?string $sObject = null)
    {
        ++$this->iterations;
        $this->memoryEnd = memory_get_usage();
        $change = $this->memoryEnd - $this->memoryStart;

        $this->totalChange += $change;
        $this->last100Change += $change;
        $this->queue->enqueue($change);
        if ($this->queue->count() > 100) {
            $this->last100Change -= $this->queue->dequeue();
        }

        if ($sObject) {
            if (!isset($this->sObjects[$sObject])) {
                $this->sObjects[$sObject] = new MemoryAnalysis($sObject);
            }
            $this->sObjects[$sObject]->_setStartMemory($this->memoryStart);
            $this->sObjects[$sObject]->next();
        }
        $this->memoryStart = $this->memoryEnd;
    }

    public function __toString(): string
    {
        $str = sprintf('{
            "totalChange": %d,
            "averageChange": %d,
            "peak": %d,
            "last100Change": %d,
            "last100AverageChange": %d,
            "sObject": %s
        }',
            $this->totalChange / 1024,
            $this->totalChange / $this->iterations / 1024,
            memory_get_peak_usage(true) / 1024,
            $this->last100Change / 1024,
            $this->last100Change / min($this->iterations, 100) / 1024,
            count($this->sObjects) ?
                "[\n".implode(", \n", array_fill(0, count($this->sObjects), '%s'))."\n]" :
                "\"$this->name\""
        );
        $args = array_map('strval', $this->sObjects);
        array_unshift($args, $str);

        return call_user_func_array('sprintf', $args);
    }
}
