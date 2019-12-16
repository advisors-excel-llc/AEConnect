<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Modules;

use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;


class Time
{
    /** @var Stopwatch */
    private $stopwatch;
    /** @var array|StopwatchEvent[]  */
    private $timers = [
        'master' => null,
        'pull' => null,
        'locate' => null,
        'sync' => null,
        'update' => null,
        'create' => null,
        'flush' => null,
    ];

    private $iterations = [
        'master' => 1,
        'pull' => 0,
        'locate' => 0,
        'sync' => 0,
        'update' => 0,
        'create' => 0,
        'flush' => 0,
    ];
    /** @var Table */
    private $table;
    /** @var ConsoleSectionOutput */
    private $output;

    public function register(EventDispatcherInterface $dispatch)
    {
        $dispatch->addListener('aeconnect.count_results_from_queries', [$this, 'receiveCounts'], 100);

        $dispatch->addListener('aeconnect.pull_records', [$this, 'pullStart'], 999);
        $dispatch->addListener('aeconnect.pull_records', [$this, 'pullComplete'], -999);

        $dispatch->addListener('aeconnect.locate_entities', [$this, 'locateStart'], 999);
        $dispatch->addListener('aeconnect.locate_entities', [$this, 'locateComplete'], -999);

        $dispatch->addListener('aeconnect.sync_sfids', [$this, 'syncStart'], 999);
        $dispatch->addListener('aeconnect.sync_sfids', [$this, 'syncComplete'], -999);

        $dispatch->addListener('aeconnect.update_entity_with_sobject', [$this, 'updateStart'], 999);
        $dispatch->addListener('aeconnect.update_entity_with_sobject', [$this, 'updateComplete'], -999);

        $dispatch->addListener('aeconnect.create_entity_with_sobject', [$this, 'createStart'], 999);
        $dispatch->addListener('aeconnect.create_entity_with_sobject', [$this, 'createComplete'], -999);

        $dispatch->addListener('aeconnect.flush', [$this, 'flushStart'], 999);
        $dispatch->addListener('aeconnect.flush', [$this, 'flushComplete'], -999);

        $dispatch->addListener('aeconnect.end', [$this, 'end'], -100);
    }


    public function receiveCounts(SyncEvent $event)
    {
        $this->stopwatch = new Stopwatch();
        $this->timers['master'] = $this->stopwatch->start('master');

        $this->output = $event->getConfig()->getOutput()->section();
        $this->table = new Table($this->output);
        $this->table->setHeaders([
            'step name',
            'iterations',
            'average',
            'total',
            'percent'
        ]);

    }

    public function pullStart(SyncTargetEvent $event)
    {
        $this->render('pulling new data.');
        if (!isset($this->timers['pull'])) {
            $this->timers['pull'] = $this->stopwatch->start('pull');
        } else {
            $this->timers['pull']->start();
        }
    }

    public function pullComplete(SyncTargetEvent $event)
    {
        $this->iterations['pull']++;
        $this->timers['pull']->stop();
    }

    public function locateStart(SyncTargetEvent $event)
    {
        $this->render('Locating sObjects in database.');
        if (!isset($this->timers['locate'])) {
            $this->timers['locate'] = $this->stopwatch->start('locate');
        } else {
            $this->timers['locate']->start();
        }
    }

    public function locateComplete(SyncTargetEvent $event)
    {
        $this->iterations['locate']++;
        $this->timers['locate']->stop();
    }

    public function syncStart(SyncTargetEvent $event)
    {
        $this->render('Syncing sObject SFIDs to database');
        if (!isset($this->timers['sync'])) {
            $this->timers['sync'] = $this->stopwatch->start('sync');
        } else {
            $this->timers['sync']->start();
        }
    }

    public function syncComplete(SyncTargetEvent $event)
    {
        $this->iterations['sync']++;
        $this->timers['sync']->stop();
    }

    public function updateStart(SyncTargetEvent $event)
    {
        $this->render('Updating pre existing entities with new sObject data ');
        if (!isset($this->timers['update'])) {
            $this->timers['update'] = $this->stopwatch->start('update');
        } else {
            $this->timers['update']->start();
        }
    }

    public function updateComplete(SyncTargetEvent $event)
    {
        $this->iterations['update']++;
        $this->timers['update']->stop();
    }

    public function createStart(SyncTargetEvent $event)
    {
        $this->render('Creating new entities from sObject data');
        if (!isset($this->timers['create'])) {
            $this->timers['create'] = $this->stopwatch->start('create');
        } else {
            $this->timers['create']->start();
        }
    }

    public function createComplete(SyncTargetEvent $event)
    {
        $this->iterations['create']++;
        $this->timers['create']->stop();
    }

    public function flushStart(SyncTargetEvent $event)
    {
        $this->render('Flushing data');
        if (!isset($this->timers['flush'])) {
            $this->timers['flush'] = $this->stopwatch->start('flush');
        } else {
            $this->timers['flush']->start();
        }
    }

    public function flushComplete(SyncTargetEvent $event)
    {
        $this->iterations['flush']++;
        $this->timers['flush']->stop();
    }

    private function render($nextStep = 'master')
    {
        $this->output->clear();
        $totalDuration = $this->timers['master']->getDuration();
        $rows = [];
        foreach (array_filter($this->timers) as $step => $timer) {
            $rows[] = [
                $step,
                $this->iterations[$step],
                $timer->getDuration() / $this->iterations[$step],
                $timer->getDuration(),
                min(100, $timer->getDuration() / $totalDuration * 100) . '%'
            ];
        }
        $rows[] = new TableSeparator();
        $rows[] = [new TableCell(" $nextStep ", ['colspan' => 5])];
        $this->table->setRows($rows);

        $this->table->render();
    }

    public function end(SyncEvent $event)
    {
        $time = $this->timers['master']->stop();

        $this->output->writeln('');
        $this->output->writeln('<info>TOTAL TIME : ' . $time->getDuration() . ' ms</info>');
        $this->output->writeln('');
        $this->table->render();
    }
}
