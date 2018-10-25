<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/24/18
 * Time: 3:12 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\SObjectTreeMaker;
use AE\ConnectBundle\Tests\KernelTestCase;

class SObjectTreeMakerTest extends KernelTestCase
{
    public function testBuild()
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->get(ConnectionManagerInterface::class)->getConnection();
        /** @var SObjectTreeMaker $treeMaker */
        $treeMaker = $this->get(SObjectTreeMaker::class);

        $tree = $treeMaker->build($connection);

        $this->assertArrayHasKey('Account', $tree);
        $this->assertArrayHasKey('S3F__Test_Object__c', $tree);
        $this->assertArrayNotHasKey('OrderItem', $tree);
        $this->assertArrayNotHasKey('Task', $tree);
        $this->assertArrayNotHasKey('Order', $tree);

        $this->assertArrayHasKey('Contact', $tree['Account']);
        $this->assertArrayNotHasKey('Task', $tree['Account']);
        $this->assertArrayNotHasKey('Order', $tree['Account']);

        $this->assertArrayHasKey('Order', $tree['Account']['Contact']);
        $this->assertArrayHasKey('Task', $tree['Account']['Contact']);

        $this->assertArrayHasKey('OrderItem', $tree['Account']['Contact']['Order']);
    }

    public function testBuildFlatMap()
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->get(ConnectionManagerInterface::class)->getConnection();
        /** @var SObjectTreeMaker $treeMaker */
        $treeMaker = $this->get(SObjectTreeMaker::class);

        $map = $treeMaker->buildFlatMap($connection);

        $this->assertEquals('Product2', $map[0]);
        $this->assertEquals('Account', $map[1]);
        $this->assertEquals('Contact', $map[2]);
        $this->assertEquals('Order', $map[3]);
        $this->assertEquals('OrderItem', $map[4]);
        $this->assertEquals('Task', $map[5]);
        $this->assertEquals('UserRole', $map[6]);
        $this->assertEquals('S3F__Test_Object__c', $map[7]);
    }
}
