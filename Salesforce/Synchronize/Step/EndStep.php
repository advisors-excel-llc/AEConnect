<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EndStep extends Step
{
    const NAME = 'aeconnect.end';
    function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    function nextStep(): Step
    {
        return new InitialStep();
    }
}
