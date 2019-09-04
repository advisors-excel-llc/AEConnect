<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 11:28 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Salesforce\Bulk\Events\Events;
use AE\ConnectBundle\Salesforce\Bulk\Events\ProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\UpdateProgressEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class BulkProgress
 *
 * @package AE\ConnectBundle\Salesforce\Bulk
 */
class BulkProgress
{
    /**
     * @var array
     */
    private $progress = [];

    /**
     * @var array
     */
    private $totals = [];

    /**
     * @var int
     */
    private $totalProgress = 0;

    /**
     * @var int
     */
    private $overallTotal = 0;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * BulkProgress constructor.
     *
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     *
     */
    protected function calculateTotalProgress(): void
    {
        $this->totalProgress = array_sum($this->progress);
    }

    /**
     *
     */
    protected function calculateOverallTotal(): void
    {
        $this->overallTotal = array_sum($this->totals);
    }

    /**
     * @param array $progress
     *
     * @return BulkProgress
     */
    public function setProgress(array $progress): self
    {
        $this->progress = $progress;
        $this->calculateTotalProgress();
        $this->dispatchEvent(Events::SET_PROGRESS);

        return $this;
    }

    /**
     * @param null|string $key
     *
     * @return int
     */
    public function getProgress(?string $key = null): int
    {
        if (null === $key) {
            return $this->totalProgress;
        }

        if (array_key_exists($key, $this->progress)) {
            return $this->progress[$key];
        }

        return 0;
    }

    /**
     * @param array $totals
     *
     * @return BulkProgress
     */
    public function setTotals(array $totals): self
    {
        $this->totals = $totals;
        $this->calculateOverallTotal();
        $this->dispatchEvent(Events::SET_TOTALS);

        return $this;
    }

    /**
     * @param null|string $key
     *
     * @return int
     */
    public function getTotal(?string $key = null): int
    {
        if (null == $key) {
            return $this->overallTotal;
        }

        if (array_key_exists($key, $this->totals)) {
            return $this->totals[$key];
        }

        return 0;
    }

    public function getTotals(): array
    {
        return $this->totals;
    }

    /**
     * @param string $key
     * @param int $progress
     *
     * @return BulkProgress
     */
    public function updateProgress(string $key, int $progress): self
    {
        $this->progress[$key] = $progress;
        $this->calculateTotalProgress();

        if (!array_key_exists($key, $this->totals)) {
            $this->totals[$key] = $progress;
            $this->calculateOverallTotal();
        }

        if ($progress > $this->totals[$key]) {
            return $this->updateTotal($key, $progress);
        }

        $this->dispatchUpdateProgressEvent($key);

        if ($this->overallTotal === 0 || $this->totalProgress / $this->overallTotal === 1) {
            $this->dispatchEvent(Events::COMPLETE);
        }

        return $this;
    }

    public function updateTotal(string $key, int $total): self
    {
        $this->totals[$key] = $total;
        $this->calculateOverallTotal();
        $this->dispatchEvent(Events::SET_TOTALS);

        $this->updateProgress($key, $this->getProgress($key));

        return $this;
    }

    /**
     * @param string $eventName
     */
    protected function dispatchEvent(string $eventName): void
    {
        $this->dispatcher->dispatch(
            $eventName,
            new ProgressEvent(
                $this->progress,
                $this->totals,
                $this->totalProgress,
                $this->overallTotal
            )
        );
    }

    /**
     * @param string $key
     */
    protected function dispatchUpdateProgressEvent(string $key): void
    {
        $this->dispatcher->dispatch(
            Events::UPDATE_PROGRESS,
            new UpdateProgressEvent(
                $key,
                $this->progress,
                $this->totals,
                $this->totalProgress,
                $this->overallTotal
            )
        );
    }
}
