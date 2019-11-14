<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CountResultsFromQueries extends Step
{
    const NAME = 'aeconnect.count_results_from_queries';

    function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    function nextStep(): Step
    {
        if ($this->syncEvent->getConfig()->needsSFIDsCleared()) {
            return new ClearSFIDs();
        }
        return new EndStep();
    }
}
