<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/22/19
 * Time: 3:51 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Salesforce\Bulk\BulkProgress;
use AE\ConnectBundle\Salesforce\Bulk\Events\Events;
use AE\ConnectBundle\Salesforce\Bulk\Events\ProgressEvent;
use AE\ConnectBundle\Tests\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class BulkProgressTest extends KernelTestCase
{
    /**
     * @var BulkProgress
     */
    private $progress;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->progress   = $this->get(BulkProgress::class);
        $this->dispatcher = $this->get(EventDispatcherInterface::class);
    }

    public function testShouldSetProgress()
    {
        $this->progress->setTotals(
            [
                'item1' => 200,
                'item2' => 600,
            ]
        );

        $this->assertEquals(200, $this->progress->getTotal('item1'));
        $this->assertEquals(600, $this->progress->getTotal('item2'));
        $this->assertEquals(800, $this->progress->getTotal());

        $this->progress->setProgress(
            [
                'item1' => 100,
                'item2' => 0,
            ]
        );

        $this->assertEquals(100, $this->progress->getProgress('item1'));
        $this->assertEquals(0, $this->progress->getProgress('item2'));
        $this->assertEquals(100, $this->progress->getProgress());

        $this->progress->updateProgress('item2', 600);

        $this->assertEquals(100, $this->progress->getProgress('item1'));
        $this->assertEquals(600, $this->progress->getProgress('item2'));
        $this->assertEquals(700, $this->progress->getProgress());

        $this->progress->updateProgress('item3', 200);

        $this->assertEquals(100, $this->progress->getProgress('item1'));
        $this->assertEquals(600, $this->progress->getProgress('item2'));
        $this->assertEquals(200, $this->progress->getProgress('item3'));
        $this->assertEquals(200, $this->progress->getTotal('item3'));
        $this->assertEquals(900, $this->progress->getProgress());

        $this->progress->updateProgress('item1', 200);

        $this->assertEquals(200, $this->progress->getProgress('item1'));
        $this->assertEquals(200, $this->progress->getTotal('item1'));
        $this->assertEquals(600, $this->progress->getProgress('item2'));
        $this->assertEquals(200, $this->progress->getProgress('item3'));
        $this->assertEquals(200, $this->progress->getTotal('item3'));
        $this->assertEquals(1000, $this->progress->getProgress());
    }

    public function testShouldDispatchEvent()
    {
        $progress  = 0;
        $total     = 0;
        $percent   = 0;
        $complete  = false;
        $listeners = [];

        $listeners[Events::SET_TOTALS] = function (ProgressEvent $event) use (&$total) {
            $total = $event->getOverallTotal();
        };

        $listeners[Events::SET_PROGRESS] = function (ProgressEvent $event) use (&$progress) {
            $progress = $event->getOverallProgress();
        };

        $listeners[Events::UPDATE_PROGRESS] = function (ProgressEvent $event) use (&$percent) {
            $percent = $event->getProgressPercent();
        };

        $listeners[Events::COMPLETE] = function (ProgressEvent $event) use (&$complete) {
            $complete = true;
        };

        foreach ($listeners as $event => $listener) {
            $this->dispatcher->addListener($event, $listener);
        }

        $this->progress->setTotals(
            [
                'item1' => 200,
                'item2' => 600,
            ]
        );

        $this->assertEquals(800, $total);

        $this->progress->setProgress(
            [
                'item1' => 200,
            ]
        );

        $this->assertEquals(200, $progress);

        $this->progress->updateProgress('item2', 600);

        $this->assertEquals(100, $percent);
        $this->assertTrue($complete);

        foreach ($listeners as $event => $listener) {
            $this->dispatcher->removeListener($event, $listener);
        }
    }
}
