<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/27/19
 * Time: 12:14 PM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

class EntitySubscriber extends AbstractEntitySubscriber
{
    /**
     * @var array
     */
    protected $upserts    = [];

    /**
     * @var array
     */
    protected $removals   = [];

    /**
     * @var array
     */
    protected $processing = [];

    protected function getUpserts(): array
    {
        return $this->upserts;
    }

    protected function getRemovals(): array
    {
        return $this->removals;
    }

    protected function getProcessing(): array
    {
        return $this->processing;
    }

    protected function saveUpserts(array $upserts)
    {
        $this->upserts = $upserts;

        return $this;
    }

    protected function saveRemovals(array $removals)
    {
        $this->removals = $removals;

        return $this;
    }

    protected function saveProcessing(array $processing)
    {
        $this->processing = $processing;

        return $this;
    }

    protected function clearUpserts()
    {
        $this->upserts = [];

        return $this;
    }

    protected function clearRemovals()
    {
        $this->removals = [];

        return $this;
    }

    protected function clearProcessing()
    {
        $this->processing = [];

        return $this;
    }

}
