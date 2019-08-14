<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/13/19
 * Time: 9:49 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\InboundQueryProcessor;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\DBAL\Connection;

class InboundQueryProcessorTest extends DatabaseTestCase
{
    /**
     * @var InboundQueryProcessor
     */
    private $processor;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SObject
     */
    private $account;

    /**
     * @var string
     */
    private $objectName;

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var Connection $connection */
        $connection = $this->doctrine->getConnection();
        $connection->exec("DELETE FROM account");

        $this->processor         = $this->get(InboundQueryProcessor::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);

        $client           = $this->connectionManager->getConnection()->getRestClient()->getSObjectClient();
        $this->objectName = 'Inbound Query Processor '.utf8_encode(random_bytes(5));
        $account          = new SObject(
            [
                'Name'                  => $this->objectName,
                'S3F__Test_Picklist__c' => 'Item 1',
            ]
        );
        $client->persist("Account", $account);

        $this->account = $account;
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testInboundQueryProcessor()
    {
        $connection = $this->connectionManager->getConnection();
        $query      = "SELECT Name, S3F__Test_Picklist__c FROM Account WHERE Name='{$this->objectName}'";
        $this->processor->process($connection, $query, true);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo    = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(1, $accounts);
        $account = $accounts[0];
        $this->assertEquals($this->objectName, $account->getName());
        $this->assertContains('Item 1', $account->getTestPicklist());
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testWildcard()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM account");

        $connection = $this->connectionManager->getConnection();
        $query      = "SELECT * FROM Account WHERE Name='{$this->objectName}'";
        $this->processor->process($connection, $query, true);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo    = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(1, $accounts);
        $account = $accounts[0];
        $this->assertEquals($this->objectName, $account->getName());
        $this->assertContains('Item 1', $account->getTestPicklist());
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testSuffix()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM account");

        $connection = $this->connectionManager->getConnection();
        $query      = "SELECT * FROM Account WHERE Name='{$this->objectName}' ORDER BY Id LIMIT 1 OFFSET 0";
        $this->processor->process($connection, $query, true);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo    = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(1, $accounts);
        $account = $accounts[0];
        $this->assertEquals($this->objectName, $account->getName());
        $this->assertContains('Item 1', $account->getTestPicklist());
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testUpdateNoInsert()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM account");

        $connection = $this->connectionManager->getConnection();
        $query      = "SELECT * FROM Account WHERE Name LIKE 'Inbound Query Processor%' ORDER BY Id LIMIT 10 OFFSET 0";
        $this->processor->process($connection, $query);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo    = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(0, $accounts);
    }

    protected function tearDown(): void
    {
        $client = $this->connectionManager->getConnection()->getRestClient()->getSObjectClient();
        $client->remove('Account', $this->account);

        parent::tearDown();
    }
}
