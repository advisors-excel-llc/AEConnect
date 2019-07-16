<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 3:53 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Enqueue\Client\DriverInterface;
use Enqueue\Consumption\Result;
use Enqueue\Fs\FsContext;

class SalesforceConnectorTest extends DatabaseTestCase
{
    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var FsContext
     */
    private $context;

    /**
     * @var DriverInterface
     */
    private $driver;

    protected function setUp()
    {
        parent::setUp();
        $this->connector = $this->get(SalesforceConnector::class);
        $this->context   = $this->get('enqueue.transport.default.context');
        $this->driver    = $this->get('enqueue.client.default.driver');

        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account;');
        $conn->exec('DELETE FROM contact;');
        $conn->exec('DELETE FROM product;');
        $conn->exec('DELETE FROM order_product;');
        $conn->exec('DELETE FROM order_table;');
    }

    public function testOutgoing()
    {
        // We don't want to fire on any triggers now do we?
        $this->connector->disable();

        $manager  = $this->doctrine->getManager();
        $items    = [];
        $queue    = $this->driver->createQueue('default');
        $consumer = $this->context->createConsumer($queue);
        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);

        $this->context->purgeQueue($queue);

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder($items);
            $manager->flush();
        }

        $this->connector->enable();

        foreach ($items as $item) {
            $this->connector->send($item);
        }

        while (null !== ($message = $consumer->receive(100))) {
            switch ($processor->process($message, $this->context)) {
                case Result::ACK:
                    $consumer->acknowledge($message);
                    break;
                case Result::REJECT:
                    $consumer->reject($message, false);
                    break;
                case Result::REQUEUE:
                    $consumer->reject($message, true);
                    break;
            }
        }

        $this->get(OutboundQueue::class)->send();

        $accounts = $manager->getRepository(Account::class)->findBy(['sfid' => null, 'connection' => 'default']);
        $this->assertEmpty($accounts);

        $orders = $manager->getRepository(Order::class)->findBy(['sfid' => null]);
        $this->assertEmpty($orders);

        $this->assertCount(5, $manager->getRepository(Account::class)->findBy(['connection' => 'default']));
    }

    public function testOutgoingDbTest()
    {
        // We don't want to fire on any triggers now do we?
        $this->connector->disable();
        $this->loadOrgConnections();

        $manager  = $this->doctrine->getManager();
        $queue    = $this->driver->createQueue('default');
        $consumer = $this->context->createConsumer($queue);
        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);
        /** @var OrgConnection $conn */
        $conn = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_test_org1']);

        $this->context->purgeQueue($queue);

        // Create one for the non-default connection
        $account = new Account();
        $account->setName('Test DB Account');
        $account->setConnections(new ArrayCollection([$conn]));
        $manager->persist($account);

        $manager->flush();

        $this->connector->enable();
        $this->connector->send($account, 'db_test_org1');

        while (null !== ($message = $consumer->receive(100))) {
            switch ($processor->process($message, $this->context)) {
                case Result::ACK:
                    $consumer->acknowledge($message);
                    break;
                case Result::REJECT:
                    $consumer->reject($message, false);
                    break;
                case Result::REQUEUE:
                    $consumer->reject($message, true);
                    break;
            }
        }

        $this->get(OutboundQueue::class)->send();

        /** @var EntityRepository $repo */
        $repo = $manager->getRepository(Account::class);
        $qb   = $repo->createQueryBuilder('a');

        $qb->join('a.connections', 'c')
           ->where('c.id = :conn')
           ->setParameter('conn', $conn->getId())
        ;

        $accounts = $qb->getQuery()->getResult();

        $this->assertCount(1, $accounts);
    }

    public function testOutgoingInactive()
    {
        // We don't want to fire on any triggers now do we?
        $this->connector->disable();
        $this->loadOrgConnections();

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $this->get(ConnectionManagerInterface::class);
        $manager           = $this->doctrine->getManager();
        $queue             = $this->driver->createQueue('default');
        $consumer          = $this->context->createConsumer($queue);

        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);
        /** @var OrgConnection $conn */
        $conn           = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_bad_org']);
        $goodConnection = $connectionManager->getConnection('db_test_org1');
        $connection     = $connectionManager->getConnection('db_bad_org');
        $this->assertFalse($connection->isActive());

        // Test with no metadata for connection
        $connection->getMetadataRegistry()->setMetadata(new ArrayCollection());

        // Create one for the non-default connection
        $account = new Account();
        $account->setName('Test Bad DB Account w/o Metadata');
        $account->setConnections(new ArrayCollection([$conn]));
        $manager->persist($account);

        $manager->flush();

        $this->connector->enable();
        $this->connector->send($account, 'db_bad_org');
        $this->connector->disable();

        $result = null;

        while (null !== ($message = $consumer->receive(100))) {
            $result = $processor->process($message, $this->context);
        }

        // If there is no metadata cached for the inactive connection, no message should be sent to the processor
        $this->assertNull($result);
        $message = null;

        // Need to mock the metadata registry to pretend like this connection was once good and is now not good
        foreach ($goodConnection->getMetadataRegistry()->getMetadata() as $metadatum) {
            $newMeta = new Metadata('db_bad_org');
            $newMeta->setClassName($metadatum->getClassName());
            $newMeta->setDescribe($metadatum->getDescribe());
            $newMeta->setConnectionNameField($metadatum->getConnectionNameField());
            $newMeta->setSObjectType($metadatum->getSObjectType());

            foreach ($metadatum->getFieldMetadata() as $fieldMetadatum) {
                $newMeta->addFieldMetadata(clone($fieldMetadatum));
            }

            $cacheId = "db_bad_org__{$metadatum->getClassName()}";
            $goodConnection->getMetadataRegistry()->getCache()->save($cacheId, $newMeta);
        }

        // Reload connections with metadata from cache
        $this->loadOrgConnections();

        $connection = $connectionManager->getConnection('db_bad_org');
        $this->assertFalse($connection->isActive());

        $this->context->purgeQueue($queue);

        // Create one for the inactive connection, now with metadata hydrated
        $account = new Account();
        $account->setName('Test Bad DB Account');
        $account->setConnections(new ArrayCollection([$conn]));
        $manager->persist($account);

        $manager->flush();

        $this->connector->enable();
        $this->connector->send($account, 'db_bad_org');

        $result = null;

        while (null !== ($message = $consumer->receive(100))) {
            $result = $processor->process($message, $this->context);
        }

        $this->assertEquals(Result::REQUEUE, $result);
    }

    public function testIncoming()
    {
        $account = new SObject(
            [
                'Id'   => '001000111000111AAA',
                'Type' => 'Account',
                'Name' => 'Test Incoming',
            ]
        );

        $account->__SOBJECT_TYPE__ = 'Account';

        $this->connector->receive($account, SalesforceConsumerInterface::CREATED);

        /** @var Account $account */
        $account = $this->doctrine->getManagerForClass(Account::class)
                                  ->getRepository(Account::class)
                                  ->findOneBy(['sfid' => '001000111000111AAA'])
        ;
        $extId   = $account->getExtId();

        $this->assertNotNull($account);
        $this->assertEquals('Test Incoming', $account->getName());

        $account                   = new SObject(
            [
                'Id'           => '001000111000111AAA',
                'Type'         => 'Account',
                'Name'         => 'Test Incoming Update',
                'S3F__hcid__c' => $extId,
            ]
        );
        $account->__SOBJECT_TYPE__ = 'Account';

        $this->connector->receive($account, SalesforceConsumerInterface::UPDATED);

        /** @var Account $account */
        $account = $this->doctrine->getManagerForClass(Account::class)
                                  ->getRepository(Account::class)
                                  ->findOneBy(['extId' => $extId])
        ;

        $this->assertNotNull($account);
        $this->assertEquals('Test Incoming Update', $account->getName());

        $contact                   = new SObject(
            [
                'Id'        => '001000111000111BBB',
                'Type'      => 'Contact',
                'AccountId' => '001000111000111AAA',
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
            ]
        );
        $contact->__SOBJECT_TYPE__ = 'Contact';

        $this->connector->receive($contact, SalesforceConsumerInterface::CREATED);

        /** @var Contact $contact */
        $contact = $this->doctrine->getManagerForClass(Contact::class)
                                  ->getRepository(Contact::class)
                                  ->findOneBy(['sfid' => '001000111000111BBB'])
        ;

        $this->assertNotNull($contact);
        $this->assertNotNull($contact->getAccount());
        $this->assertEquals($account->getId(), $contact->getAccount()->getId());

        $contact = new SObject(
            [
                'Id'   => '001000111000111BBB',
                'Type' => 'Contact',
            ]
        );

        $contact->__SOBJECT_TYPE__ = 'Contact';

        $this->connector->receive($contact, SalesforceConsumerInterface::DELETED);
        /** @var Contact $contact */
        $contact = $this->doctrine->getManagerForClass(Contact::class)
                                  ->getRepository(Contact::class)
                                  ->findOneBy(['sfid' => '001000111000111BBB'])
        ;

        $this->assertNull($contact);
    }

    public function testIncomingDBTest()
    {
        $this->loadOrgConnections();

        $account                   = new SObject(
            [
                'Id'   => '001000111000111ZAA',
                'Name' => 'Test Recieving DBAL',
                'Type' => 'Account',
            ]
        );
        $account->__SOBJECT_TYPE__ = 'Account';

        $this->connector->receive($account, SalesforceConsumerInterface::CREATED, 'db_test_org1');

        /** @var EntityRepository $repo */
        $repo = $this->doctrine->getManagerForClass(Account::class)
                               ->getRepository(Account::class)
        ;

        $qb = $repo->createQueryBuilder('a');
        $qb->join('a.sfids', 's')
           ->join('s.connection', 'c')
           ->where('c.name = :conn AND s.salesforceId = :sfid')
           ->setParameters(
               [
                   'conn' => 'db_test_org1',
                   'sfid' => '001000111000111ZAA',
               ]
           )
        ;

        $account = $qb->getQuery()->getOneOrNullResult();

        $this->assertNotNull($account);
        $this->assertEquals('Test Recieving DBAL', $account->getName());
    }

    public function testDeadEntityManager()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM task");
        $sfid = SfidGenerator::generate();

        $this->connector->receive(
            new SObject(
                [
                    'Id'               => $sfid,
                    'Type'             => 'Task',
                    'Subject'          => 'Test Valid Insert',
                    'Status'           => 'Open',
                    '__SOBJECT_TYPE__' => 'Task',
                ]
            ),
            SalesforceConsumerInterface::CREATED
        );

        /** @var EntityManagerInterface $objectManager */
        $objectManager = $this->doctrine->getManagerForClass(Task::class);
        /** @var Account $account */
        $task = $objectManager
            ->getRepository(Task::class)
            ->findOneBy(['sfid' => $sfid])
        ;
        $this->assertNotNull($task);

        $this->connector->receive(
            new SObject(
                [
                    'Id'               => SfidGenerator::generate(),
                    'Type'             => 'Task',
                    'Subject'          => 'Test Failed Task',
                    '__SOBJECT_TYPE__' => 'Task',
                ]
            ),
            SalesforceConsumerInterface::CREATED
        );

        $this->assertFalse($this->doctrine->getManagerForClass(Task::class)->isOpen());

        $sfid2 = SfidGenerator::generate();

        $this->connector->receive(
            new SObject(
                [
                    'Id'               => $sfid2,
                    'Subject'          => 'Test After Failed Account',
                    'Type'             => 'Task',
                    '__SOBJECT_TYPE__' => 'Task',
                    'Status'           => 'Open',
                ]
            ),
            SalesforceConsumerInterface::CREATED
        );

        $account2 = $objectManager->getRepository(Account::class)->findBy(['sfid' => $sfid2]);
        $this->assertNotNull($account2);
    }

    public function testDeadEntityManagerBulk()
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM task");
        $sfid = SfidGenerator::generate();
        $sfid2 = SfidGenerator::generate();

        $this->connector->receive(
            [
                new SObject(
                    [
                        'Id'               => $sfid,
                        'Type'             => 'Task',
                        'Subject'          => 'Test Valid Insert',
                        'Status'           => 'Open',
                        '__SOBJECT_TYPE__' => 'Task',
                    ]
                ),
                new SObject(
                    [
                        'Id'               => SfidGenerator::generate(),
                        'Type'             => 'Task',
                        'Subject'          => 'Test Failed Task',
                        '__SOBJECT_TYPE__' => 'Task',
                    ]
                ),
                new SObject(
                    [
                        'Id'               => $sfid2,
                        'Subject'          => 'Test After Failed Account',
                        'Type'             => 'Task',
                        '__SOBJECT_TYPE__' => 'Task',
                        'Status'           => 'Open',
                    ]
                ),
            ],
            SalesforceConsumerInterface::CREATED
        );

        /** @var EntityManagerInterface $objectManager */
        $objectManager = $this->doctrine->getManagerForClass(Task::class);
        /** @var Account $account */
        $task = $objectManager
            ->getRepository(Task::class)
            ->findOneBy(['sfid' => $sfid])
        ;
        $this->assertNotNull($task);
        $this->assertFalse($this->doctrine->getManagerForClass(Task::class)->isOpen());
        $account2 = $objectManager->getRepository(Account::class)->findBy(['sfid' => $sfid2]);
        $this->assertNotNull($account2);
    }

    private function createOrder(array &$queue, string $connectionName = 'default')
    {
        $manager = $this->doctrine->getManager();
        $account = new Account();
        $account->setName(uniqid('Test Customer '));
        $account->setConnection($connectionName);

        $manager->persist($account);
        $queue[] = $account;

        $contact = new Contact();
        $contact->setFirstName('Test');
        $contact->setLastName(uniqid('Contact '));
        $contact->setAccount($account);

        $manager->persist($contact);
        $queue[] = $contact;

        $product = new Product();
        $product->setName(uniqid('Custom Product '));
        $product->setActive(true);

        $manager->persist($product);
        $queue[] = $product;

        $order = new Order();
        $order->setAccount($account);
        $order->setShipToContact($contact);
        $order->setStatus(Order::DRAFT);
        $order->setEffectiveDate(new \DateTime());

        $manager->persist($order);
        $queue[] = $order;
    }
}
