<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 3:53 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Streaming\ChannelSubscriberInterface;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\SalesforceRestSdk\Model\SObject;
use Enqueue\Client\DriverInterface;
use Enqueue\Consumption\Result;
use Enqueue\Fs\FsContext;

class SalesforceConnectorTest extends DatabaseTestCase
{
    /**
     * @var SalesforceConnector
     */
    private $connector;

    /** @var FsContext */
    private $context;

    /** @var DriverInterface */
    private $driver;

    protected function loadSchemas(): array
    {
        return [
            Account::class,
            Contact::class,
            Order::class,
            Product::class,
            Task::class,
            TestObject::class,
        ];
    }

    protected function setUp()
    {
        parent::setUp();
        $this->connector = $this->get(SalesforceConnector::class);
        $this->context   = $this->get('enqueue.transport.context');
        $this->driver    = $this->get('enqueue.client.driver');
    }

    public function testOutgoing()
    {
        $manager  = $this->doctrine->getManager();
        $items    = [];
        $queue    = $this->driver->createQueue('default');
        $consumer = $this->context->createConsumer($queue);
        /** @var OutboundProcessor $processor */
        $processor = $this->get(OutboundProcessor::class);

        $this->context->purge($queue);

        // We don't want to fire on any triggers now do we?
        $this->connector->disable();
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

        $accounts = $manager->getRepository(Account::class)->findBy(['sfid' => null]);
        $this->assertEmpty($accounts);

        $orders = $manager->getRepository(Order::class)->findBy(['sfid' => null]);
        $this->assertEmpty($orders);
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

        $this->connector->receive($account, ChannelSubscriberInterface::CREATED);

        /** @var Account $account */
        $account = $this->doctrine->getManagerForClass(Account::class)
                                  ->getRepository(Account::class)
                                  ->findOneBy(['sfid' => '001000111000111AAA'])
        ;
        $extId   = $account->getExtId();

        $this->assertNotNull($account);
        $this->assertEquals('Test Incoming', $account->getName());

        $account = new SObject(
            [
                'Id'   => '001000111000111AAA',
                'Type' => 'Account',
                'Name' => 'Test Incoming Update',
            ]
        );

        $this->connector->receive($account, ChannelSubscriberInterface::UPDATED);

        /** @var Account $account */
        $account = $this->doctrine->getManagerForClass(Account::class)
                                  ->getRepository(Account::class)
                                  ->findOneBy(['extId' => $extId])
        ;

        $this->assertNotNull($account);
        $this->assertEquals('Test Incoming Update', $account->getName());

        $contact = new SObject(
            [
                'Id'        => '001000111000111BBB',
                'Type'      => 'Contact',
                'AccountId' => '001000111000111AAA',
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
            ]
        );

        $this->connector->receive($contact, ChannelSubscriberInterface::CREATED);

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

        $this->connector->receive($contact, ChannelSubscriberInterface::DELETED);
        /** @var Contact $contact */
        $contact = $this->doctrine->getManagerForClass(Contact::class)
                                  ->getRepository(Contact::class)
                                  ->findOneBy(['sfid' => '001000111000111BBB'])
        ;

        $this->assertNull($contact);
    }

    private function createOrder(array &$queue)
    {
        $manager = $this->doctrine->getManager();
        $account = new Account();
        $account->setName(uniqid('Test Customer '));

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
