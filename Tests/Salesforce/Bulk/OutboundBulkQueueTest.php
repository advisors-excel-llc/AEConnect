<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 4:59 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\OutboundBulkQueue;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use Doctrine\Common\Collections\ArrayCollection;

class OutboundBulkQueueTest extends DatabaseTestCase
{
    public function testProcess()
    {
        $this->loadFixtures(['./Tests/Resources/config/bulk_outbound.yml']);

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection = $connectionManager->getConnection();

        /** @var OutboundBulkQueue $outboundQueue */
        $outboundQueue = $this->get(OutboundBulkQueue::class);

        $outboundQueue->process($connection, ['Account'], true);

        $accounts = $this->doctrine->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $this->assertNotNull($accounts[0]->getSfid());
    }

    public function testProcessDBTest()
    {
        $this->loadOrgConnections();

        $manager     = $this->getDoctrine()->getManager();
        $conn        = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_test_org1']);
        $accountSfid = new SalesforceId();
        $accountSfid->setConnection($conn)
            ->setSalesforceId('111000111000111ADA')
        ;
        $account = new Account();
        $account->setName('Test Account')
            ->setConnections(new ArrayCollection([$conn]))
            ->setSfids([$accountSfid])
            ;

        $manager->persist($account);
        $manager->flush();

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection = $connectionManager->getConnection('db_test_org1');

        /** @var OutboundBulkQueue $outboundQueue */
        $outboundQueue = $this->get(OutboundBulkQueue::class);

        $outboundQueue->process($connection, ['Account']);

        $accounts = $this->doctrine->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $this->assertNotEmpty($accounts[0]->getSfids());
    }
}
