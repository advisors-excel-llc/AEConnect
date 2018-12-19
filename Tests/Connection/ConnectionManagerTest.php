<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 9:40 AM
 */

namespace AE\ConnectBundle\Tests\Connection;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Role;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Tests\Entity\TestObject;

class ConnectionManagerTest extends DatabaseTestCase
{
    protected function loadSchemas(): array
    {
        return [
            OrgConnection::class,
            Account::class,
            Product::class,
            Order::class,
            OrderProduct::class,
            Role::class,
            Contact::class,
            Task::class,
            TestObject::class
        ];
    }

    public function testManager()
    {
        $this->loadFixtures([__DIR__.'/../Resources/config/login_fixtures.yml']);

        /** @var DbalConnectionDriver $dbalLoader */
        $dbalLoader = $this->get(DbalConnectionDriver::class);
        $dbalLoader->loadConnections();

        /** @var ConnectionManagerInterface $manager */
        $manager    = $this->get('ae_connect.connection_manager');
        $connection = $manager->getConnection('default');

        $this->assertNotNull($connection);
        $this->assertEquals('default', $connection->getName());
        $this->assertTrue($connection->isDefault());
        $this->assertNotNull($connection->getRestClient());
        $this->assertNotNull($connection->getStreamingClient());
        $this->assertNotNull($connection->getBulkClient());
        $this->assertEquals(100000, $connection->getBulkApiMinCount());

        $connection = $manager->getConnection('db_test_org1');

        $this->assertNotNull($connection);
        $this->assertEquals('db_test_org1', $connection->getName());
        $this->assertEquals('db_test', $connection->getAlias());
        $this->assertFalse($connection->isDefault());
        $this->assertNotNull($connection->getRestClient());
        $this->assertNotNull($connection->getStreamingClient());
        $this->assertNotNull($connection->getBulkClient());
        $this->assertEquals(100000, $connection->getBulkApiMinCount());

        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $this->assertNotNull($metadata);
        $this->assertNotNull($metadata->getDescribe());
        $this->assertEquals('db_test_org1', $metadata->getConnectionName());

        $this->assertArraySubset(
            ['sfid' => 'Id', 'name' => 'Name', 'extId' => 'AE_Connect_Id__c'],
            $metadata->getPropertyMap()
        );
    }
}
