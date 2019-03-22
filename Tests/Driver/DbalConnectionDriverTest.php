<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/22/19
 * Time: 10:50 AM
 */

namespace AE\ConnectBundle\Tests\Driver;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Organization;

class DbalConnectionDriverTest extends DatabaseTestCase
{
    /**
     * @var DbalConnectionDriver
     */
    private $dbalDriver;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->dbalDriver = $this->get(DbalConnectionDriver::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    public function testNewConnection()
    {
        $org = new Organization();
        $org->setName('test_new')
            ->setLabel('Test New')
            ->setActive(false)
            ->setUsername(getenv('SF_ALT_USER'))
            ->setClientKey(getenv('SF_CLIENT_ID'))
            ->setClientSecret(getenv('SF_CLIENT_SECRET'))
            ->setLoginUrl('https://login.salesforce.com/')
            ->setRedirectUri('http://localhost')
        ;

        $manager = $this->doctrine->getManagerForClass(Organization::class);
        $manager->persist($org);
        $manager->flush();

        $this->dbalDriver->loadConnections();

        $connection = $this->connectionManager->getConnection('test_new');
        $this->assertFalse($connection->isActive());
    }
}
