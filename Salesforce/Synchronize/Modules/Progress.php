<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Modules;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Progress
{
    /** @var  OutputInterface */
    protected $output;
    /** @var ProgressBar */
    protected $progressBar;
    protected $counts = [];

    public function register(EventDispatcherInterface $dispatch)
    {
        $dispatch->addListener('aeconnect.count_results_from_queries', [$this, 'receiveCounts'], -100);
        $dispatch->addListener('aeconnect.flush', [$this, 'incrementCounts'], -100);
    }

    public function receiveCounts(SyncEvent $event)
    {
        $this->output = $event->getConfig()->getOutput();
    }

    public function incrementCounts(SyncTargetEvent $event)
    {
        $sObject = $event->getTarget()->name;

        if (!isset($this->counts[$sObject])) {
            if ($this->progressBar) {
                $this->progressBar->finish();
            }
            $this->generateProgressBar($event);
        }

        $createCount = count($event->getTarget()->getNewEntities());
        $updateCount = count($event->getTarget()->getUpdateEntities());

        $this->counts[$sObject] = [
            'create' => $this->counts[$sObject]['create'] + $createCount,
            'update' => $this->counts[$sObject]['update'] + $updateCount,
            'skip'   => $this->counts[$sObject]['skip'] + $event->getTarget()->batchSize - ($createCount + $updateCount),
        ];
        $create = $this->counts[$sObject]['create'];
        $update = $this->counts[$sObject]['update'];
        $skip = $this->counts[$sObject]['skip'];

        $this->progressBar->setMessage("$sObject ( creates : $create  |  updates : $update  |  skips : $skip )");
        $this->progressBar->advance($event->getTarget()->batchSize);
    }

    private function generateProgressBar(SyncTargetEvent $event)
    {
        $sObject = $event->getTarget()->name;
        $this->output->writeln('');
        $this->output->writeln('<info>Starting ' . $sObject . '</info>');
        $this->counts[$sObject] = [
            'create' => 0,
            'update' => 0,
            'skip'   => 0,
        ];

        $create = $this->counts[$sObject]['create'];
        $update = $this->counts[$sObject]['update'];
        $skip = $this->counts[$sObject]['skip'];

        $pbar = new ProgressBar($this->output, $event->getTarget()->count);
        $pbar->setFormat('  %current%/%max%  %percent:3s%% %elapsed:6s%/%estimated:-6s%  %memory:6s% -- %message%');
        $pbar->setMessage("$sObject ( creates : $create  |  updates : $update  |  skips : $skip )");
        $pbar->start();

        $this->progressBar = $pbar;
    }
}
