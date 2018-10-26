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

class InboundBulkQueueTest extends DatabaseTestCase
{
    protected function loadSchemas(): array
    {
        return [
            Account::class
        ];
    }

    public function testProcess()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection = $connectionManager->getConnection();

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account'], true);

        $accounts = $this->doctrine->getManager()->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $this->assertNotNull($accounts[0]->getSfid());
    }
}
