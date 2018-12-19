<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 4:37 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\InboundBulkQueue;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Role;

class InboundBulkQueueTest extends DatabaseTestCase
{
    protected function loadSchemas(): array
    {
        return [
            Account::class,
            Role::class,
        ];
    }

    public function testProcess()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection        = $connectionManager->getConnection();

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account', 'UserRole'], true);

        $accounts = $this->doctrine->getManager()->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $this->assertNotNull($accounts[0]->getSfid());

        /** @var Role $role */
        $role = $this->doctrine->getManager()->getRepository(Role::class)
                               ->findOneBy(['developerName' => 'Director_of_Testing'])
        ;

        $this->assertNotNull($role);
        $this->assertNotNull($role->getParent());
        $this->assertEquals('CEO', $role->getParent()->getDeveloperName());
    }

    public function testProcessRecordTypeFiltering()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection        = $connectionManager->getConnection('db_test_org1');

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account'], true);

        /** @var array|Account[] $accounts */
        $accounts = $this->doctrine->getManager()->getRepository(Account::class)
                                                 ->findBy(['connection' => 'db_test_org1']);
        $this->assertNotEmpty($accounts);

        $filtered = array_filter(
            $accounts,
            function (Account $account) {
                return $account->getName() === 'Test Client Account';
            }
        );

        $this->assertCount(1, $filtered);
    }
}
