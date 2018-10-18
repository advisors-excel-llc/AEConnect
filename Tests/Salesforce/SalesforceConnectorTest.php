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
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Task;
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
            OrderProduct::class,
            Task::class,
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

        $accounts = $manager->getRepository(Account::class)->findBy(['extId' => null]);
        $this->assertEmpty($accounts);

        //$orderitems = $manager->getRepository(OrderProduct::class)->findBy(['extId' => null]);
        //$this->assertEmpty($orderitems);
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

        $manager->persist($order);
        $queue[] = $order;

        $orderItem = new OrderProduct();
        $orderItem->setOrder($order);
        $orderItem->setProduct($product);
        $orderItem->setQuantity(1);
        $orderItem->setUnitPrice(100);
        $orderItem->setTotalPrice(100);

        $manager->persist($orderItem);
        $queue[] = $orderItem;
    }
}
