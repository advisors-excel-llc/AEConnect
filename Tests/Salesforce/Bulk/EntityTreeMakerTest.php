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
use AE\ConnectBundle\Salesforce\Bulk\EntityTreeMaker;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\ConnectBundle\Tests\KernelTestCase;

class EntityTreeMakerTest extends KernelTestCase
{
    public function testBuild()
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->get(ConnectionManagerInterface::class)->getConnection();
        /** @var EntityTreeMaker $treeMaker */
        $treeMaker = $this->get(EntityTreeMaker::class);

        $tree = $treeMaker->build($connection);

        $this->assertArrayHasKey(Account::class, $tree);
        $this->assertArrayHasKey(TestObject::class, $tree);
        $this->assertArrayNotHasKey(OrderProduct::class, $tree);
        $this->assertArrayNotHasKey(Task::class, $tree);
        $this->assertArrayNotHasKey(Order::class, $tree);

        $this->assertArrayHasKey(Contact::class, $tree[Account::class]);
        $this->assertArrayNotHasKey(Task::class, $tree[Account::class]);
        $this->assertArrayNotHasKey(Order::class, $tree[Account::class]);

        $this->assertArrayHasKey(Order::class, $tree[Account::class][Contact::class]);
        $this->assertArrayHasKey(Task::class, $tree[Account::class][Contact::class]);

        $this->assertArrayHasKey(OrderProduct::class, $tree[Account::class][Contact::class][Order::class]);
    }
}
