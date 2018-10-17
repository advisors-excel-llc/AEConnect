<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 12:50 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Queue\QueueProcessor;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Util\ItemizedCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class QueueProcessorTest extends DatabaseTestCase
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SObjectCompiler
     */
    private $compiler;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();

        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
        $this->compiler          = $this->get(SObjectCompiler::class);
    }

    protected function loadSchemas(): array
    {
        return [
            Account::class,
            Contact::class,
            Order::class,
            OrderProduct::class,
            Product::class,
            Task::class,
        ];
    }

    public function testQueue()
    {
        $this->loadFixtures(
            [
                'Tests/Resources/config/fixtures.yml',
            ]
        );

        $toQueue = new ArrayCollection(
            [
                CompilerResult::INSERT => new ItemizedCollection(),
                CompilerResult::UPDATE => new ItemizedCollection(),
                CompilerResult::DELETE => new ItemizedCollection(),
            ]
        );
        $newAccounts = $this->createAccounts($toQueue);
        $this->createContactsForAccounts($toQueue, $newAccounts);
        $newContacts = $this->createContacts($toQueue);
        $this->createOrders($toQueue, $newContacts);
        $updatedAccounts = $this->updateAccounts($toQueue);
        $updatedContacts = $this->updateContacts($toQueue);
        $this->createOrders($toQueue, $updatedContacts);
        $this->deleteContacts($toQueue);
        //$this->addOrderItemsToExisting($toQueue);
        $this->createTasks($toQueue, $newAccounts);
        $this->createTasks($toQueue, $updatedAccounts);


        $queue = QueueProcessor::buildQueue(
            $toQueue->get(CompilerResult::INSERT),
            $toQueue->get(CompilerResult::UPDATE),
            $toQueue->get(CompilerResult::DELETE)
        );

        $this->assertNotEmpty($queue);
        $this->assertCount(8, $queue->getKeys());
    }

    private function createAccounts(ArrayCollection $queue)
    {
        $manager = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);
        $accounts = [];

        for ($i = 401; $i < 421; $i++) {
            $account = new Account();
            $account->setName('Test Insert '.$i);
            $manager->persist($account);
            $result = $this->compiler->compile($account);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
            $accounts[] = $account;
        }

        return $accounts;
    }

    /**
     * @param ArrayCollection $queue
     * @param array|Account[] $accounts
     *
     * @return array
     */
    private function createContactsForAccounts(ArrayCollection $queue, array $accounts)
    {
        $manager = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);
        $contacts = [];

        foreach ($accounts as $i => $account) {
            $contact = new Contact();
            $contact->setFirstName('Insert');
            $contact->setLastName('Contact '.($i + 400));
            $contact->setAccount($account);
            $manager->persist($contact);
            $result = $this->compiler->compile($contact);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function createContacts(ArrayCollection $queue)
    {
        $manager = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);
        $contacts = [];

        for ($i = 421; $i < 441; $i++) {
            $contact = new Contact();
            $contact->setFirstName('Insert');
            $contact->setLastName("Contact $i");
            $manager->persist($contact);
            $result = $this->compiler->compile($contact);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function createOrders(ArrayCollection $queue, array $contacts)
    {
        $manager  = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);

        $product = new Product();
        $product->setName('Product '.uniqid());
        $product->setActive(true);

        $manager->persist($product);
        $prodResult = $this->compiler->compile($product);
        $collection->set($prodResult->getReferenceId(), $prodResult, $prodResult->getMetadata()->getSObjectType());

        $orders = [];

        /** @var Contact $contact */
        foreach ($contacts as $contact) {
            $order = new Order();
            $order->setAccount($contact->getAccount());
            $order->setShipToContact($contact);
            $orders[] = $order;
            $manager->persist($order);
            $result = $this->compiler->compile($order);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }

        $this->createOrderItems($queue, $orders, [$product]);
    }

    private function createOrderItems(ArrayCollection $queue, array $orders, array $products)
    {
        $manager  = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);

        /** @var Order $order */
        foreach ($orders as $order) {
            /** @var Product $product */
            foreach ($products as $product) {
                $item = new OrderProduct();
                $item->setOrder($order);
                $item->setProduct($product);
                $item->setUnitPrice(20);
                $item->setTotalPrice(20);
                $item->setQuantity(1);
                $item->setAvailableQuantity(1000);
                $manager->persist($item);
                $result = $this->compiler->compile($item);
                $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
            }
        }
    }

    private function createTasks(ArrayCollection $queue, array $accounts)
    {
        $manager  = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);

        /** @var Account $account */
        foreach ($accounts as $account) {
            $task = new Task();
            $task->setAccount($account);
            $task->setSubject('Test Task for '.$account->getName());
            $manager->persist($task);
            $result = $this->compiler->compile($task);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }
    }

    /**
     * @param ArrayCollection $queue
     *
     * @return array|object[]
     */
    private function updateAccounts(ArrayCollection $queue)
    {
        $manager  = $this->doctrine->getManager();
        $accounts = $manager->getRepository(Account::class)->findBy([], null, 100);
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::UPDATE);

        /** @var Account $account */
        foreach ($accounts as $account) {
            $account->setName('Update Contact '.$account->getId());
            $result = $this->compiler->compile($account);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }

        return $accounts instanceof Collection ? $accounts->toArray() : $accounts;
    }

    private function updateContacts(ArrayCollection $queue)
    {
        $manager  = $this->doctrine->getManager();
        $contacts = $manager->getRepository(Contact::class)->findBy([], null, 400);
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::UPDATE);

        /** @var Contact $contact */
        foreach ($contacts as $contact) {
            $contact->setFirstName('Updated');
            $result = $this->compiler->compile($contact);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }

        return $contacts instanceof Collection ? $contacts->toArray() : $contacts;
    }

    private function addOrderItemsToExisting(ArrayCollection $queue)
    {
        $manager = $this->doctrine->getManager();
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::INSERT);
        /** @var Product $product */
        $product = $manager->getRepository(Product::class)->find(1);
        $orders = $manager->getRepository(Order::class)->findBy([], null, 10);

        /** @var Order $order */
        foreach ($orders as $order) {
            $item = new OrderProduct();
            $item->setTotalPrice(50);
            $item->setUnitPrice(10);
            $item->setQuantity(5);
            $item->setProduct($product);
            $item->setOrder($order);
            $manager->persist($item);
            $result = $this->compiler->compile($item);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }
    }

    private function deleteContacts(ArrayCollection $queue)
    {
        $manager = $this->doctrine->getManager();
        /** @var Contact $contacts */
        $contacts = $manager->getRepository(Contact::class)->findBy([], null, 100, 400);
        /** @var ItemizedCollection $collection */
        $collection = $queue->get(CompilerResult::DELETE);

        foreach ($contacts as $contact) {
            $manager->remove($contact);
            $result = $this->compiler->compile($contact);
            $collection->set($result->getReferenceId(), $result, $result->getMetadata()->getSObjectType());
        }
    }
}
