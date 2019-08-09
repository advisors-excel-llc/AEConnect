<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/23/19
 * Time: 10:34 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer\Util;

use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Util\ConnectionFinder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;

class ConnectionFinderTest extends DatabaseTestCase
{
    /**
     * @var ConnectionFinder
     */
    private $connectionFinder;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionFinder = $this->get(ConnectionFinder::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    public function testFindForAccount()
    {
        $this->loadOrgConnections();
        $connection = $this->connectionManager->getConnection('db_test_org1');

        $this->assertNotNull($connection);

        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);

        $this->assertNotNull($metadata);

        $entity = $this->connectionFinder->find('db_test_org1', $metadata);

        $this->assertNotNull($entity);
        $this->assertInstanceOf(ConnectionEntityInterface::class, $entity);
        $this->assertEquals('db_test_org1', $entity->getName());
    }

    public function testFindForContact()
    {
        $this->loadOrgConnections();
        $connection = $this->connectionManager->getConnection('db_test_org1');

        $this->assertNotNull($connection);

        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Contact::class);

        $this->assertNotNull($metadata);

        $entity = $this->connectionFinder->find('db_test_org1', $metadata);

        $this->assertNotNull($entity);
        $this->assertInstanceOf(ConnectionEntityInterface::class, $entity);
        $this->assertEquals('db_test_org1', $entity->getName());
    }

    public function testFindOnClassWithNoConnection()
    {
        $this->loadOrgConnections();
        $connection = $this->connectionManager->getConnection('default');

        $this->assertNotNull($connection);

        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Order::class);

        $this->assertNotNull($metadata);

        $entity = $this->connectionFinder->find('default', $metadata);

        $this->assertNull($entity);
    }
}
