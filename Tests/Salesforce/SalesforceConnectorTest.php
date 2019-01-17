<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 3:53 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\ConnectionEntity;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\SalesforceRestSdk\Model\SObject;
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
        $this->context   = $this->get('enqueue.transport.context');
        $this->driver    = $this->get('enqueue.client.driver');
    }

    public function testOutgoing()
    {
        // We don't want to fire on any triggers now do we?
        $this->connector->disable();
        $this->loadOrgConnections();

        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

        $manager  = $this->doctrine->getManager();
        $items    = [];
        $queue    = $this->driver->createQueue('default');
        $consumer = $this->context->createConsumer($queue);
        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);

        if (method_exists($this->context, 'purge')) {
            $this->context->purge($queue);
        }

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

        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

        $manager  = $this->doctrine->getManager();
        $queue    = $this->driver->createQueue('default');
        $consumer = $this->context->createConsumer($queue);
        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);
        /** @var ConnectionEntity $conn */
        $conn = $manager->getRepository(ConnectionEntity::class)->findOneBy(['name' => 'db_test_org1']);

        if (method_exists($this->context, 'purge')) {
            $this->context->purge($queue);
        }

        // Create one for the non-default connection
        $account = new Account();
        $account->setName('Test DB Account');
        $account->setConnections([$conn]);
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

        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

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
                               ->getRepository(Account::class);

        $qb = $repo->createQueryBuilder('a');
        $qb->join('a.sfids', 's')
            ->join('s.connection', 'c')
            ->where('c.name = :conn AND s.salesforceId = :sfid')
            ->setParameters([
                'conn' => 'db_test_org1',
                'sfid' => '001000111000111ZAA'
            ])
            ;

        $account = $qb->getQuery()->getOneOrNullResult();

        $this->assertNotNull($account);
        $this->assertEquals('Test Recieving DBAL', $account->getName());
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
