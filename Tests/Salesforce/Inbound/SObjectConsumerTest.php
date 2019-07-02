<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 7/2/19
 * Time: 2:10 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Inbound;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Inbound\Compiler\EntityCompiler;
use AE\ConnectBundle\Salesforce\Inbound\SObjectConsumer;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;
use AE\SalesforceRestSdk\Bayeux\Channel;
use AE\SalesforceRestSdk\Bayeux\Message;
use AE\SalesforceRestSdk\Bayeux\Salesforce\StreamingData;
use AE\SalesforceRestSdk\Model\SObject;
use Psr\Log\LoggerInterface;

class SObjectConsumerTest extends DatabaseTestCase
{
    public function testClientFiltering()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        /** @var EntityCompiler $entityCompiler */
        $entityCompiler = $this->get(EntityCompiler::class);
        $entityCompiler->setLogger($this->get(LoggerInterface::class));
        $accountChannel = new Channel('/data/AccountChangeEvent');
        $taskChannel    = new Channel('/data/TaskChangeEvent');
        $consumer       = new SObjectConsumer(
            $this->get(SalesforceConnector::class),
            $this->get(LoggerInterface::class)
        );

        $connection = $connectionManager->getConnection('default');
        $connection->setPermittedFilteredObjects(['Account']);
        $connection->setAppFilteringEnabled(true);
        $connection->setAppName('AEConnect');

        $consumer->addConnection($connection);

        // Test that Accounts get through, cause they're permitted for this connection
        $accountMessage = new Message();
        $accountData    = new StreamingData();
        $accountSfid    = SfidGenerator::generate();
        $accountData->setPayload(
            [
                'ChangeEventHeader' => [
                    'recordIds'    => [$accountSfid],
                    'changeOrigin' => "/services/data/v46.0/Account;client=AEConnect",
                    'changeType'   => "CREATE",
                    'entityName'   => 'Account',
                ],
                'Name'              => 'Test Account From Stream',
            ]
        );
        $accountMessage->setSuccessful(true)
                       ->setChannel($accountChannel->getChannelId())
                       ->setData($accountData)
        ;

        $consumer->consume($accountChannel, $accountMessage);

        $accounts = $entityCompiler->compile(
            new SObject(
                [
                    'Id'               => $accountSfid,
                    '__SOBJECT_TYPE__' => 'Account',
                ]
            ),
            'default',
            false
        );
        $this->assertCount(1, $accounts);
        $account = array_shift($accounts);
        $this->assertNotNull($account);
        $this->assertNotNull($account->getId());
        $this->assertEquals('Test Account From Stream', $account->getName());

        // Filter out a Task that was issued by the same connection that's consuming it
        $taskSfid = SfidGenerator::generate();
        $taskData = new StreamingData();
        $taskData->setPayload(
            [
                'ChangeEventHeader' => [
                    'recordIds'    => [$taskSfid],
                    'changeOrigin' => "/services/data/v46.0/Task;client=AEConnect",
                    'changeType'   => "CREATE",
                    'entityName'   => 'Task',
                ],
                'Subject'           => 'Test Product From Stream',
            ]
        );
        $taskMessage = new Message();
        $taskMessage->setChannel($taskChannel->getChannelId())
                    ->setSuccessful(true)
                    ->setData($taskData)
        ;

        $consumer->consume($taskChannel, $taskMessage);

        $tasks = $entityCompiler->compile(
            new SObject(['Id' => $taskSfid, '__SOBJECT_TYPE__' => 'Task']),
            'default',
            false
        );
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertNull($task->getId());

        // If another app did an update to the Task, the change should be allowed
        $taskData->setPayload(
            [
                'ChangeEventHeader' => [
                    'recordIds'    => [$taskSfid],
                    'changeOrigin' => "/services/data/v46.0/Task;client=other_account",
                    'changeType'   => "UPDATE",
                    'entityName'   => 'Task',
                ],
                'Subject'           => 'Test Task From Stream',
                'Status'            => 'Open',
            ]
        );

        $consumer->consume($taskChannel, $taskMessage);

        $tasks = $entityCompiler->compile(
            new SObject(['Id' => $taskSfid, '__SOBJECT_TYPE__' => 'Task']),
            'default',
            false
        );
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertNotNull($task->getId());
        $this->assertEquals('Test Task From Stream', $task->getSubject());
    }
}
