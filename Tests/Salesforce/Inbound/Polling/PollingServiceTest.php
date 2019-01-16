<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 7:04 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Inbound\Polling;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Inbound\Polling\PollingService;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Role;
use AE\SalesforceRestSdk\Model\SObject;
use AE\SalesforceRestSdk\Rest\SObject\Client;

class PollingServiceTest extends DatabaseTestCase
{
    /**
     * @var PollingService
     */
    private $polling;

    /**
     * @var Client
     */
    private $client;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->polling = $this->get(PollingService::class);
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $this->client = $connectionManager->getConnection()->getRestClient()->getSObjectClient();
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testPolling()
    {
        $objects = $this->polling->getObjects();
        $this->assertNotEmpty($objects);

        $result = $this->client->query('SELECT Id FROM UserRole WHERE DeveloperName=\'CEO\'');

        $this->assertEquals(1, $result->getTotalSize());

        $ceo = current($result->getRecords());

        $this->assertNotFalse($ceo);

        $object = new SObject([
            'Name' => 'Test Role',
            'DeveloperName' => 'TestRole',
            'ParentRoleId' => $ceo->Id
        ]);

        $success = $this->client->persist('UserRole', $object);
        $this->assertTrue($success);
        $this->assertNotNull($object->Id);

        $object->Name = 'Test Role Update';
        $success = $this->client->persist('UserRole', $object);
        $this->assertTrue($success);

        $this->polling->poll();

        $role = $this->doctrine->getManager()
                               ->getRepository(Role::class)
                               ->findOneBy(['developerName' => 'TestRole'])
        ;

        $this->assertNotNull($role);

        $this->client->remove('UserRole', $object);

        $this->polling->poll();

        $role = $this->doctrine->getManager()
                               ->getRepository(Role::class)
                               ->findOneBy(['developerName' => 'TestRole'])
        ;

        $this->assertNull($role);
    }
}
