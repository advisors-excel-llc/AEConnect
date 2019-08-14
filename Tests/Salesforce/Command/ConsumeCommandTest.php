<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/9/19
 * Time: 2:25 PM
 */

namespace AE\ConnectBundle\Tests\Command;

use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use Doctrine\DBAL\Connection;
use Enqueue\Client\DriverInterface;
use Interop\Queue\Context;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ConsumeCommandTest extends DatabaseTestCase
{
    /**
     * @var SalesforceConnector
     */
    private $connector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connector = $this->get(SalesforceConnector::class);

        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account;');
    }

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account;');
        parent::tearDown();
    }

    public function testCommand()
    {
        /** @var Context $context */
        $context = $this->get('enqueue.transport.ae_connect.context');
        /** @var DriverInterface $driver */
        $driver = $this->get('enqueue.client.ae_connect.driver');
        $queue = $driver->createQueue('default');
        $consumer = $context->createConsumer($queue);

        $context->purgeQueue($queue);

        $account = new Account();
        $account->setName('Test Consumer Command')
            ->setExtId(Uuid::uuid4());


        $this->connector->send($account, 'db_bad_org');
        $this->connector->send($account, 'default');

        $app = new Application(static::$kernel);
        $command = $app->find('ae_connect:consume');
        $tester = new CommandTester($command);
        $tester->execute(['command' => $command->getName(), '--message-limit' => 2]);

        $results = [];

        while (null !== ($message = $consumer->receive(100))) {
            $results[] = $message;
        }

        $this->assertCount(1, $results);
    }
}
