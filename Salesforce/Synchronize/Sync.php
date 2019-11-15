<?php

namespace AE\ConnectBundle\Salesforce\Synchronize;

use AE\ConnectBundle\Salesforce\Synchronize\Step\EndStep;
use AE\ConnectBundle\Salesforce\Synchronize\Step\InitialStep;
use AE\ConnectBundle\Salesforce\Synchronize\Step\Step;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Sync
{
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var Step
     */
    private $step;

    /** @var SyncEvent */
    private $syncEvent;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function sync(Configuration $config)
    {
        $this->syncEvent = new SyncEvent($config);
        $this->step = new InitialStep();

        do {
            $this->step->setContext($this->syncEvent);
            $this->step->execute($this->dispatcher);
            $this->step = $this->step->nextStep();
        } while (!$this->step instanceof InitialStep);
    }
}
