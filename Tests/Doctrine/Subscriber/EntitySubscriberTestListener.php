<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/27/19
 * Time: 1:19 PM
 */

namespace AE\ConnectBundle\Tests\Doctrine\Subscriber;

use AE\ConnectBundle\Doctrine\Subscriber\EntitySubscriberInterface;

/**
 * Class EntitySubscriberTestListener
 *
 * @package AE\ConnectBundle\Tests\Doctrine\Subscriber
 */
class EntitySubscriberTestListener
{
    /**
     * @var EntitySubscriberInterface
     */
    private $entitySubscriber;

    /**
     * @var array
     */
    private $upserts = [];

    /**
     * @var array
     */
    private $removals = [];

    /**
     * @var array
     */
    private $processing = [];

    /**
     * EntitySubscriberTestListener constructor.
     *
     * @param EntitySubscriberInterface $entitySubscriber
     */
    public function __construct(EntitySubscriberInterface $entitySubscriber)
    {
        $this->entitySubscriber = $entitySubscriber;
    }

    /**
     * @param $method
     *
     * @return \Closure
     * @throws \ReflectionException
     */
    private function exposeGetter($method): \Closure
    {
        $ref    = new \ReflectionClass(get_class($this->entitySubscriber));
        $getter = $ref->getMethod($method);
        $getter->setAccessible(true);

        return $getter->getClosure($this->entitySubscriber);
    }

    /**
     * @throws \ReflectionException
     */
    public function postPersist()
    {
        $this->upserts = $this->exposeGetter('getUpserts')->__invoke();
    }

    /**
     * @throws \ReflectionException
     */
    public function postRemove()
    {
        $this->removals = $this->exposeGetter('getRemovals')->__invoke();
    }

    /**
     * @throws \ReflectionException
     */
    public function postFlush()
    {
        $this->processing = $this->exposeGetter('getProcessing')->__invoke();
    }

    /**
     * @return mixed
     */
    public function getEntitySubscriber()
    {
        return $this->entitySubscriber;
    }

    /**
     * @return array
     */
    public function getUpserts(): array
    {
        return $this->upserts;
    }

    /**
     * @return array
     */
    public function getRemovals(): array
    {
        return $this->removals;
    }

    /**
     * @return array
     */
    public function getProcessing(): array
    {
        return $this->processing;
    }
}
