<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class GatherTargetSObjectsStep
 * @package AE\ConnectBundle\Salesforce\Step
 * If there were no queries or targets issued by the user, we will want to
 */
class GatherTargetSObjectsStep extends Step
{
    const NAME = 'aeconnect.gather_target_sobjects';

    public function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    /**
     * Once we've gotten target objects we need to create queries based off of those targets.
     * @return Step
     */
    public function nextStep(): Step
    {
        return new GenerateQueriesFromTargets();
    }
}
