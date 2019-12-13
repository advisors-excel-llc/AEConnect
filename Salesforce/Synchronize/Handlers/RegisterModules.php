<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Salesforce\Synchronize\Modules\Progress;
use AE\ConnectBundle\Salesforce\Synchronize\Modules\Time;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class RegisterModules implements SyncHandler
{
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function process(SyncEvent $event): void
    {
        if ($event->getConfig()->debugCount()) {
            $progress = new Progress();
            $progress->register($this->dispatcher);
        }
        if ($event->getConfig()->debugTime()) {
            $time = new Time();
            $time->register($this->dispatcher);
        }
    }
}
