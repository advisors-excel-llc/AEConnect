<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 11:44 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk\Events;

use Symfony\Component\EventDispatcher\Event;

/**
 * Class ProgressEvent
 *
 * @package AE\ConnectBundle\Salesforce\Bulk\Events
 */
class ProgressEvent extends Event
{
    /**
     * @var array|int[]
     */
    private $progress = [];

    /**
     * @var array|int[]
     */
    private $totals = [];

    /**
     * @var int
     */
    private $overallProgress = 0;

    /**
     * @var int
     */
    private $overallTotal = 0;

    /**
     * ProgressEvent constructor.
     *
     * @param array $progress
     * @param array $totals
     * @param int $overallProgress
     * @param int $overallTotal
     */
    public function __construct(
        array $progress = [],
        array $totals = [],
        int $overallProgress = 0,
        int $overallTotal = 0
    ) {
        $this->progress = $progress;
        $this->totals = $totals;
        $this->overallProgress = $overallProgress;
        $this->overallTotal = $overallTotal;
    }

    /**
     * @return array|int[]
     */
    public function getProgress()
    {
        return $this->progress;
    }

    /**
     * @param string $key
     *
     * @return int
     */
    public function getProgressFor(string $key): int
    {
        if (array_key_exists($key, $this->progress)) {
            return $this->progress[$key];
        }

        return 0;
    }

    /**
     * @return float
     */
    public function getProgressPercent(): float
    {
        return ($this->overallProgress / $this->overallTotal) * 100;
    }

    /**
     * @param string $key
     *
     * @return float
     */
    public function getProgressPercentFor(string $key): float
    {
        if (array_key_exists($key, $this->progress)
            && array_key_exists($key, $this->totals)
            && 0 < $this->totals[$key]
        ) {
            return ($this->progress[$key] / $this->totals[$key]) * 100;
        }

        return 0;
    }

    /**
     * @return array|int[]
     */
    public function getTotals()
    {
        return $this->totals;
    }

    /**
     * @param string $key
     *
     * @return int
     */
    public function getTotal(string $key): int
    {
        if (array_key_exists($key, $this->totals)) {
            return $this->totals[$key];
        }

        return 0;
    }

    /**
     * @return int
     */
    public function getOverallProgress(): int
    {
        return $this->overallProgress;
    }

    /**
     * @return int
     */
    public function getOverallTotal(): int
    {
        return $this->overallTotal;
    }
}
