<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 4:59 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\OutboundBulkQueue;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Role;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Tests\Entity\TestObject;

class OutboundBulkQueueTest extends DatabaseTestCase
{
    protected function loadSchemas(): array
    {
        return [
            Account::class,
            OrderProduct::class,
            Product::class,
            Order::class,
            Task::class,
            Contact::class,
            Role::class,
            TestObject::class,
        ];
    }

    public function testProcess()
    {
        $this->loadFixtures(['./Tests/Resources/config/bulk_outbound.yml']);

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection = $connectionManager->getConnection();

        /** @var OutboundBulkQueue $outboundQueue */
        $outboundQueue = $this->get(OutboundBulkQueue::class);

        $outboundQueue->process($connection, ['Account'], true);

        $accounts = $this->doctrine->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $this->assertNotNull($accounts[0]->getSfid());
    }
}
