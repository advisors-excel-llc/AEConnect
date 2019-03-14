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

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();

        /** @var Connection $connection */
        $connection = $this->doctrine->getConnection();
        $connection->exec("DELETE FROM account");

        $this->processor = $this->get(InboundQueryProcessor::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);

        $client = $this->connectionManager->getConnection()->getRestClient()->getSObjectClient();

        $account = new SObject(
            [
                'Name' => 'Inbound Query Processor',
                'S3F__Test_Picklist__c' => 'Item 1'
            ]
        );
        $client->persist("Account", $account);

        $this->account = $account;
    }

    public function testInboundQueryProcessor()
    {
        $connection = $this->connectionManager->getConnection();
        $query = "SELECT Name, S3F__Test_Picklist__c FROM Account WHERE Name='Inbound Query Processor'";
        $this->processor->process($connection, $query);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(1, $accounts);
        $account = $accounts[0];
        $this->assertEquals('Inbound Query Processor', $account->getName());
        $this->assertContains('Item 1', $account->getTestPicklist());
    }

    public function testWildcard()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM account");

        $connection = $this->connectionManager->getConnection();
        $query = "SELECT * FROM Account WHERE Name='Inbound Query Processor'";
        $this->processor->process($connection, $query);

        $manager = $this->doctrine->getManagerForClass(Account::class);
        $repo = $manager->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repo->findAll();

        $this->assertCount(1, $accounts);
        $account = $accounts[0];
        $this->assertEquals('Inbound Query Processor', $account->getName());
        $this->assertContains('Item 1', $account->getTestPicklist());
    }

    protected function tearDown()
    {
        $client = $this->connectionManager->getConnection()->getRestClient()->getSObjectClient();
        $client->remove('Account', $this->account);

        parent::tearDown();
    }
}
