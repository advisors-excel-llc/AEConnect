<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OutboundUpdate extends Step
{
    const NAME = 'aeconnect.outbound_update';

    function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    function nextStep(): Step
    {
        return new EndStep();
    }
}
