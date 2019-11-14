<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class GenerateQueriesFromTargets
 * @package AE\ConnectBundle\Salesforce\Step
 * We should have a list of S object targets during this step in our event.  We can leverage this list to build a set of queries to be ran
 * to gather needed data from salesforce.
 */
class GenerateQueriesFromTargets extends Step
{
    const NAME = 'aeconnect.generate_queries';
    function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    /**
     * Now we will want to count the results that we get back from these salesforce queries.
     * @return Step
     */
    function nextStep(): Step
    {
        return new CountResultsFromQueries();
    }
}
