<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 9:40 AM
 */

namespace AE\ConnectBundle\Tests\Connection;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Tests\KernelTestCase;

class ConnectionManagerTest extends KernelTestCase
{
    public function testManager()
    {
        /** @var ConnectionManagerInterface $manager */
        $manager    = $this->get('ae_connect.connection_manager');
        $connection = $manager->getConnection('default');

        $this->assertNotNull($connection);
        $this->assertEquals('default', $connection->getName());
        $this->assertTrue($connection->isDefault());
        $this->assertNotNull($connection->getRestClient());
        $this->assertNotNull($connection->getStreamingClient());
        $this->assertNotNull($connection->getBulkClient());
    }
}
