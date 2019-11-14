<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * WHY STATE PATTERN?
 * I'm glad you asked!  Clearly we want this software to be ultra scalable and much faster, so why are we doing this feat
 * where we have a finite state machine going through transitions and all the states do is dispatch actions across the dispatcher?
 * Virtually every strategy ever would run faster, but this has some distinct goals in mind
 * 1)  Keeps why we are running code and running code totally separate.
 *      the function getting the next step is determining why we are running a particular step and execution is only dispatching an event, which other participants can subscribe to.
 *      This keeps our classes very smol, simple and focused on doing exactly one thing at a time.
 * 2)  Allows modules to be added without complicating other code
 *          The dispatcher will let us optionally run a bunch of different modules like timing, memory usage, and diagnostic
 *          like actions without interfering or adding to any code that is already implemented.
 * 3)  DRYs up current processes
 *          A much larger problem the current process has beyond over complicated functions and no easy way to add diagnostics is that it
 *          repeats a lot of basic actions over and over again, such as entity location.  By having a state and transitioning
 *          from 1 state to the next keeps us from having to duplicate any work
 * 4)  centralizes data during bulk processes
 *          The SyncEvent and configuration will be holding all of the data that we use throughout the process,
 *          making keeping track of our data usage and configuration settings much simpler than current works.
 * 5)  Extensible and Overwritable
 *          The dispatcher allows users to insert custom code at virtually any point during execution, as well as calling
 *          stopPropagation, allowing users to completely overwrite anything AEConnect would otherwise do during any step.
 * Efficiency in this seemingly inefficient high overhead state pattern will be found through never having to run code twice
 * and focusing on true bulk processes at the I/O level as well as PHP level, minimizing redundancy and I/O times as much as possible.
 * The state pattern ensures that we write this in a clear, predictable way that is easy to debug and keeps code modular and to a minimum.
 * Class Step
 * @package AE\ConnectBundle\Salesforce\Step
 */
abstract class Step
{
    /** @var SyncEvent */
    protected $syncEvent;

    public function setContext(SyncEvent $syncEvent)
    {
        $this->syncEvent = $syncEvent;
    }

    abstract function execute(EventDispatcherInterface $dispatcher) : void;
    abstract function nextStep() : Step;
}
