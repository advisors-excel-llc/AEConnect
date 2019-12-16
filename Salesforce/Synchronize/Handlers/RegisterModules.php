<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Salesforce\Synchronize\Modules\Errors;
use AE\ConnectBundle\Salesforce\Synchronize\Modules\Progress;
use AE\ConnectBundle\Salesforce\Synchronize\Modules\Time;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RegisterModules implements SyncHandler
{
    use LoggerAwareTrait;

    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher, LoggerInterface $logger)
    {
        $this->dispatcher = $dispatcher;
        $this->setLogger($logger ?: new NullLogger());
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
        if ($event->getConfig()->debugErrors()) {
            $errors = new Errors();
            $errors->register($this->dispatcher);
            $errors->setLogger($this->logger);
        }
    }
}
