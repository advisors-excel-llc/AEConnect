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
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Role;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use Doctrine\ORM\EntityRepository;
use function foo\func;

class InboundBulkQueueTest extends DatabaseTestCase
{

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

        if (null !== $role->getParent()) {
            $this->assertEquals('CEO', $role->getParent()->getDeveloperName());
        }
    }

    public function testProcessRecordTypeFiltering()
    {
        $this->loadOrgConnections();

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection        = $connectionManager->getConnection('db_test_org1');

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account'], true);

        /** @var EntityRepository $repo */
        $repo = $this->doctrine->getManager()->getRepository(Account::class);
        $qb   = $repo->createQueryBuilder('a');
        $qb->join('a.sfids', 's')
           ->join('s.connection', 'c')
           ->where('c.name = :conn')
           ->setParameter('conn', 'db_test_org1')
        ;

        /** @var array|Account[] $accounts */
        $accounts = $qb->getQuery()->getResult();

        $this->assertNotEmpty($accounts);

        $filtered = array_filter(
            $accounts,
            function (Account $account) {
                return $account->getName() === 'Test Client Account';
            }
        );

        $this->assertCount(1, $filtered);
    }

    public function testNoUpdate()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connection        = $connectionManager->getConnection();
        /** @var SalesforceConnector $connector */
        $connector = $this->get(SalesforceConnector::class);
        $connector->disable();

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account'], true);

        /** @var Account[] $accounts */
        $accounts = $this->doctrine->getManager()->getRepository(Account::class)->findAll();

        $this->assertNotEmpty($accounts);
        $account = $accounts[0];
        $sfid    = $account->getSfid();
        $this->assertNotNull($sfid);

        $rand = mt_rand(1000, 100000);
        $account->setName("Testo Accounto For Sync $rand");
        $account->setSfid(null);

        $this->doctrine->getManager()->flush();

        /** @var Account $bAccount */
        $bAccount = $this->doctrine->getManager()->getRepository(Account::class)->findOneBy(
            [
                'name' => "Testo Accounto For Sync $rand",
            ]
        )
        ;

        $this->assertNotNull($bAccount);
        $this->assertNull($bAccount->getSfid());

        $inboundQueue->process($connection, ['Account'], false);

        $cAccount = $this->doctrine->getManager()->getRepository(Account::class)->findOneBy(
            [
                'name' => "Testo Accounto For Sync $rand",
            ]
        )
        ;

        $this->assertNotNull($cAccount);

        /** @var Account $dAccount */
        $dAccount = $this->doctrine->getManager()->getRepository(Account::class)->findOneBy(
            [
                'sfid'       => $sfid,
                'connection' => $connection->getName(),
            ]
        )
        ;

        $this->assertNotNull($dAccount);
        $this->assertEquals($sfid, $dAccount->getSfid());
        $this->assertEquals($account->getName(), $dAccount->getName());

        $connector->enable();
    }

    public function testNoUpdateMulti()
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $connName          = 'db_test_org1';
        $connection        = $connectionManager->getConnection($connName);
        /** @var SalesforceConnector $connector */
        $connector = $this->get(SalesforceConnector::class);
        $connector->disable();

        /** @var InboundBulkQueue $inboundQueue */
        $inboundQueue = $this->get(InboundBulkQueue::class);

        $inboundQueue->process($connection, ['Account'], true);

        /** @var EntityRepository $repository */
        $repository = $this->doctrine->getManager()->getRepository(Account::class);
        /** @var Account[] $accounts */
        $accounts = $repository->findAll();

        $this->assertNotEmpty($accounts);
        $account = $accounts[0];
        $sfids   = $account->getSfids();
        /** @var SalesforceId $sfid */
        $sfid    = $sfids->filter(
            function (SalesforceId $salesforceId) use ($connName) {
                return $salesforceId->getConnection()->getName() === $connName;
            }
        )->first()
        ;

        $rand = mt_rand(1000, 100000);
        $account->setName("Testo Accounto For Sync $rand");
        $account->setSfids(
            $sfids->filter(
                function (SalesforceId $salesforceId) use ($connName) {
                    return $salesforceId->getConnection()->getName() !== $connName;
                }
            )
        );

        $this->doctrine->getManager()->flush();

        /** @var Account $bAccount */
        $bAccount = $repository->findOneBy(
            [
                'name' => "Testo Accounto For Sync $rand",
            ]
        );

        $this->assertNotNull($bAccount);
        $this->assertCount(
            0,
            $bAccount->getSfids()->filter(
                function (SalesforceId $salesforceId) use ($connName) {
                    return $salesforceId->getConnection()->getName() === $connName;
                }
            )
        );

        $inboundQueue->process($connection, ['Account'], false);

        $cAccount = $repository->findOneBy(
            [
                'name' => "Testo Accounto For Sync $rand",
            ]
        );

        $this->assertNotNull($cAccount);

        $builder = $repository->createQueryBuilder('a');
        $builder->join('a.sfids', 's')
                ->join('s.connection', 'c')
            ->where(
                $builder->expr()->eq('c.name', ':conn_name'),
                $builder->expr()->eq('s.salesforceId', ':sfid')
            )
            ->setParameter(':conn_name', $connName)
            ->setParameter(':sfid', $sfid->getSalesforceId())
        ;

        /** @var Account $dAccount */
        $dAccount = $builder->getQuery()->getOneOrNullResult();

        $this->assertNotNull($dAccount);
        $this->assertEquals($account->getName(), $dAccount->getName());

        $connector->enable();
    }
}
