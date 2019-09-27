<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 11:28 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Salesforce\Bulk\Events\CompleteEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\CompleteSectionEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\ProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\SetProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\SetTotalsEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\UpdateProgressEvent;
use AE\ConnectBundle\Salesforce\Bulk\Events\UpdateTotalEvent;
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
     * @param array $progress
     *
     * @return BulkProgress
     */
    public function setProgress(array $progress): self
    {
        foreach ($progress as $key => $amount) {
            $this->updateProgress($key, $amount);
        }

        $this->dispatchEvent(SetProgressEvent::class);

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
        foreach ($totals as $key => $amount) {
            $this->updateTotal($key, $amount);
        }

        $this->dispatchEvent(SetTotalsEvent::class);

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

    /**
     * @return array
     */
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
        $oldProgress          = array_key_exists($key, $this->progress) ? $this->progress[$key] : 0;
        $this->progress[$key] = $progress;
        $this->totalProgress  += $progress - $oldProgress;

        if (!array_key_exists($key, $this->totals)) {
            $this->totals[$key] = $progress;
            $this->overallTotal += $progress;
        }

        if ($progress > $this->totals[$key]) {
            return $this->updateTotal($key, $progress);
        }

        $this->dispatchUpdateProgressEvent($key);

        if ($this->progress[$key] === $this->totals[$key]) {
            $this->dispatchCompleteSectionProgressEvent($key);
        }

        if ($this->overallTotal === 0 || $this->totalProgress / $this->overallTotal === 1) {
            $this->setComplete();
        }

        return $this;
    }

    /**
     * @param string $key
     * @param int $total
     *
     * @return BulkProgress
     */
    public function updateTotal(string $key, int $total): self
    {
        $oldTotal           = array_key_exists($key, $this->totals) ? $this->totals[$key] : 0;
        $this->totals[$key] = $total;
        $this->overallTotal += $total - $oldTotal;
        $this->dispatchUpdateTotalEvent($key);

        return $this->updateProgress($key, $this->getProgress($key));
    }

    /**
     * @param null|string $key
     *
     * @return BulkProgress
     */
    public function setComplete(?string $key = null): self
    {
        if (null === $key) {
            $keys = array_merge(array_keys($this->progress), array_keys($this->totals));

            foreach ($keys as $subKey) {
                $this->setComplete($subKey);
            }

            $this->dispatchEvent(CompleteEvent::class);
        } else {
            $progress = array_key_exists($key, $this->progress) ? $this->progress[$key] : 0;
            $total    = array_key_exists($key, $this->totals) ? $this->totals[$key] : 0;

            if ($progress < $total) {
                $this->updateProgress($key, $total);
                $this->dispatchCompleteSectionProgressEvent($key);
            }
        }

        return $this;
    }

    /**
     * @param string $class
     */
    protected function dispatchEvent(string $class): void
    {
        if ($class === ProgressEvent::class
            || (false !== ($parents = class_parents($class))
                && in_array(ProgressEvent::class, $parents))
        ) {
            $this->dispatcher->dispatch(
                $class::create(
                    $this->progress,
                    $this->totals,
                    $this->totalProgress,
                    $this->overallTotal
                )
            );
        }
    }

    /**
     * @param string $key
     */
    protected function dispatchUpdateProgressEvent(string $key): void
    {
        $this->dispatcher->dispatch(
            new UpdateProgressEvent(
                $key,
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
    protected function dispatchUpdateTotalEvent(string $key): void
    {
        $this->dispatcher->dispatch(
            new UpdateTotalEvent(
                $key,
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
    protected function dispatchCompleteSectionProgressEvent(string $key): void
    {
        $this->dispatcher->dispatch(
            new CompleteSectionEvent(
                $key,
                $this->progress,
                $this->totals,
                $this->totalProgress,
                $this->overallTotal
            )
        );
    }
}
