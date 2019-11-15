<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Step;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class InitialStep
 * @package AE\ConnectBundle\Salesforce\Step
 *
 * The first step for syncing.  Nothing in AEConnect is currently listening for this step through the dispatcher but
 * users can listen on this channel and take actions before any execution begins.  This step for AEConnect is primarily
 * going to decide where we want to start, based on the users input.
 */
class InitialStep extends Step
{
    const NAME = 'aeconnect.initial';

    function execute(EventDispatcherInterface $dispatcher): void
    {
        $dispatcher->dispatch($this->syncEvent, self::NAME);
    }

    function nextStep(): Step
    {
        $config = $this->syncEvent->getConfig();
        //If the user has given us any queries, we can skip ahead a few steps to the run queries step
        if ($config->hasQueries()) {
            return new CountResultsFromQueries();
        }
        //If the user has given us a single target object, we can skip ahead to computing queries based on targets step
        if ($config->needsQueriesGenerated() && count($config->getSObjectTargets()) == 1) {
            return new GenerateQueriesFromTargets();
        }
        //If the user has given us neither of those things, we have to go to the next step and compile a full target list to sync
        if ($config->needsTargetObjects() || count($config->getSObjectTargets()) > 1) {
            return new GatherTargetSObjectsStep();
        }
        //If we aren't doing any pulling, lets get on to pushing
        if ($config->getPushConfiguration()->update || $config->getPushConfiguration()->create) {
            return new OutboundUpdate();
        }
        return new EndStep();
    }
}
