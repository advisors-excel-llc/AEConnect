<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Modules;

use Doctrine\DBAL\Logging\SQLLogger;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class Database implements SQLLogger
{
    use LoggerAwareTrait;

    /** @var Table */
    private $table;
    /** @var ConsoleSectionOutput */
    private $output;

    protected $qCount = 0;
    protected $lastQCount = 0;

    protected $qTime = 0;
    protected $lastQTime = 0;

    protected $steps = [
        'locate' => ['iterations' => 0, 'count' => 0, 'time' => 0],
        'transform' => ['iterations' => 0, 'count' => 0, 'time' => 0],
        'flush' => ['iterations' => 0, 'count' => 0, 'time' => 0],
    ];

    /** @var Stopwatch */
    protected $watch;

    public function register(EventDispatcherInterface $dispatch)
    {
        $this->watch = new Stopwatch();
        $dispatch->addListener('aeconnect.count_results_from_queries', [$this, 'receiveCounts'], 0);

        $dispatch->addListener('aeconnect.locate_entities', [$this, 'start'], 255);
        $dispatch->addListener('aeconnect.locate_entities', [$this, 'locateComplete'], -255);

        $dispatch->addListener('aeconnect.transform_associations', [$this, 'start'], 999);
        $dispatch->addListener('aeconnect.transform', [$this, 'transformComplete'], -999);

        $dispatch->addListener('aeconnect.flush', [$this, 'start'], 255);
        $dispatch->addListener('aeconnect.flush', [$this, 'flushComplete'], -255);
    }

    public function start(SyncTargetEvent $event)
    {
        $this->lastQCount = $this->qCount;
        $this->lastQTime = $this->qTime;
    }

    public function locateComplete(SyncTargetEvent $event)
    {
        $this->complete('locate');
    }

    public function transformComplete(SyncTargetEvent $event)
    {
        $this->complete('transform');
    }

    public function flushComplete(SyncTargetEvent $event)
    {
        $this->complete('flush');
        $this->render();
    }

    private function complete($curStep)
    {
        $this->steps[$curStep]['iterations']++;
        $this->steps[$curStep]['count'] += $this->qCount - $this->lastQCount;
        $this->steps[$curStep]['time'] += $this->qTime - $this->lastQTime;
    }

    public function receiveCounts(SyncEvent $event)
    {
        $this->output = $event->getConfig()->getOutput()->section();
        $this->table = new Table($this->output);

        $this->table->setHeaders([
            'step',
            'queries',
            'averageQueries',
            'queryTime',
            'averageTime'
        ]);
    }

    private function render()
    {
        $rows = [];
        foreach ($this->steps as $step => $stats) {
            $rows[] = [
                $step,
                $stats['count'],
                $stats['count'] / $stats['iterations'],
                $stats['time'],
                $stats['time'] / $stats['iterations']
            ];
        }
        $rows[] = new TableSeparator();
        $rows[] = [new TableCell(" Total queries : $this->qCount time : $this->qTime ", ['colspan' => 3])];
        $this->table->setRows($rows);

        $this->table->render();
        $this->output->clear();
    }

    /**
     * Logs a SQL statement somewhere.
     *
     * @param string $sql The SQL to be executed.
     * @param mixed[]|null $params The SQL parameters.
     * @param int[]|string[]|null $types The SQL parameter types.
     *
     * @return void
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null)
    {
        $this->qCount++;
        $this->watch->start('query');
    }

    /**
     * Marks the last started query as stopped. This can be used for timing of queries.
     *
     * @return void
     */
    public function stopQuery()
    {
        $event = $this->watch->stop('query');
        $this->qTime += $event->getDuration();
    }
}
