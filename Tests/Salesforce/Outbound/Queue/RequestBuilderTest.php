<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/16/18
 * Time: 3:27 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Queue\RequestBuilder;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrderProduct;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\Common\Collections\ArrayCollection;
use Faker\Test\Provider\Collection;

class RequestBuilderTest extends DatabaseTestCase
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

    public function testBuild()
    {
        $this->loadFixtures(
            [
                'Tests/Resources/config/fixtures.yml',
            ]
        );

        $queue = new ArrayCollection();

        $newAccounts = $this->createAccounts($queue);
        $this->createContactsForAccounts($queue, $newAccounts);
        $newContacts = $this->createContacts($queue);
        $this->createOrders($queue, $newContacts);
        $updatedAccounts = $this->updateAccounts($queue);
        $updatedContacts = $this->updateContacts($queue);
        $this->createOrders($queue, $updatedContacts);
        $this->deleteContacts($queue);
        $this->createTasks($queue, $newAccounts);
        $this->createTasks($queue, $updatedAccounts);

        $list    = RequestBuilder::build($queue);
        $request = RequestBuilder::buildRequest(
            $list[CompilerResult::INSERT],
            $list[CompilerResult::UPDATE],
            $list[CompilerResult::DELETE]
        );

        $requests = $request->getCompositeRequest();
        $this->assertCount(8, $requests);

        $insert1 = $requests[0];
        $this->assertEquals('POST', $insert1->getMethod());
        $this->assertLessThanOrEqual(200, $insert1->getBody()->getRecords()->count());

        $insert2 = $requests[1];
        $this->assertEquals('POST', $insert2->getMethod());
        $this->assertLessThanOrEqual(200, $insert2->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($insert2->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the first group
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertEquals($insert1->getReferenceId(), $matches['value']);
                }
            }
        }

        $insert3 = $requests[2];
        $this->assertEquals('POST', $insert3->getMethod());
        $this->assertLessThanOrEqual(200, $insert3->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($insert3->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the previous groups
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertTrue(
                        in_array(
                            $matches['value'],
                            [
                                $insert1->getReferenceId(),
                                $insert2->getReferenceId(),
                            ]
                        )
                    );
                }
            }
        }

        $insert4 = $requests[3];
        $this->assertEquals('POST', $insert4->getMethod());
        $this->assertLessThanOrEqual(200, $insert4->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($insert4->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the previous groups
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertTrue(
                        in_array(
                            $matches['value'],
                            [
                                $insert1->getReferenceId(),
                                $insert2->getReferenceId(),
                                $insert3->getReferenceId(),
                            ]
                        )
                    );
                }
            }
        }

        $update1 = $requests[4];
        $this->assertEquals('PATCH', $update1->getMethod());
        $this->assertLessThanOrEqual(200, $update1->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($update1->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the previous groups
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertTrue(
                        in_array(
                            $matches['value'],
                            [
                                $insert1->getReferenceId(),
                                $insert2->getReferenceId(),
                                $insert3->getReferenceId(),
                                $insert4->getReferenceId(),
                            ]
                        )
                    );
                }
            }
        }

        $update2 = $requests[5];
        $this->assertEquals('PATCH', $update2->getMethod());
        $this->assertLessThanOrEqual(200, $update2->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($update2->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the previous groups
                // Can't reference updates cause they have no body in the response
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertTrue(
                        in_array(
                            $matches['value'],
                            [
                                $insert1->getReferenceId(),
                                $insert2->getReferenceId(),
                                $insert3->getReferenceId(),
                                $insert4->getReferenceId(),
                            ]
                        )
                    );
                }
            }
        }

        $update3 = $requests[6];
        $this->assertEquals('PATCH', $update3->getMethod());
        $this->assertLessThanOrEqual(200, $update3->getBody()->getRecords()->count());

        /** @var CompositeSObject $record */
        foreach ($update3->getBody()->getRecords() as $record) {
            foreach ($record->getFields() as $value) {
                $this->assertNotInstanceOf(ReferencePlaceholder::class, $value);
                $matches = [];
                // Everything in the second group with a reference should reference the previous groups
                // Can't reference updates cause they have no body in the response
                if (false != preg_match('/\@\{(?<value>[^\.]+)\..+?\}/', $value, $matches)) {
                    $this->assertTrue(
                        in_array(
                            $matches['value'],
                            [
                                $insert1->getReferenceId(),
                                $insert2->getReferenceId(),
                                $insert3->getReferenceId(),
                                $insert4->getReferenceId(),
                            ]
                        )
                    );
                }
            }
        }

        $delete1 = $requests[7];
        $this->assertEquals('DELETE', $delete1->getMethod());
        $this->assertLessThanOrEqual(200, $delete1->getBody()->getRecords()->count());
    }

    private function createAccounts(ArrayCollection $queue)
    {
        $manager  = $this->doctrine->getManager();
        $accounts = [];

        for ($i = 401; $i < 421; $i++) {
            $account = new Account();
            $account->setName('Test Insert '.$i);
            $manager->persist($account);
            $result = $this->compiler->compile($account);
            $queue->add($result);
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
        $manager  = $this->doctrine->getManager();
        $contacts = [];

        foreach ($accounts as $i => $account) {
            $contact = new Contact();
            $contact->setFirstName('Insert');
            $contact->setLastName('Contact '.($i + 400));
            $contact->setAccount($account);
            $manager->persist($contact);
            $result = $this->compiler->compile($contact);
            $queue->add($result);
            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function createContacts(ArrayCollection $queue)
    {
        $manager  = $this->doctrine->getManager();
        $contacts = [];

        for ($i = 421; $i < 441; $i++) {
            $contact = new Contact();
            $contact->setFirstName('Insert');
            $contact->setLastName("Contact $i");
            $manager->persist($contact);
            $result = $this->compiler->compile($contact);
            $queue->add($result);
            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function createOrders(ArrayCollection $queue, array $contacts)
    {
        $manager = $this->doctrine->getManager();

        $product = new Product();
        $product->setName('Product '.uniqid());
        $product->setActive(true);

        $manager->persist($product);
        $prodResult = $this->compiler->compile($product);
        $queue->add($prodResult);

        $orders = [];

        /** @var Contact $contact */
        foreach ($contacts as $contact) {
            $order = new Order();
            $order->setAccount($contact->getAccount());
            $order->setShipToContact($contact);
            $orders[] = $order;
            $manager->persist($order);
            $result = $this->compiler->compile($order);
            $queue->add($result);
        }

        $this->createOrderItems($queue, $orders, [$product]);
    }

    private function createOrderItems(ArrayCollection $queue, array $orders, array $products)
    {
        $manager = $this->doctrine->getManager();

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
                $queue->add($result);
            }
        }
    }

    private function createTasks(ArrayCollection $queue, array $accounts)
    {
        $manager = $this->doctrine->getManager();

        /** @var Account $account */
        foreach ($accounts as $account) {
            $task = new Task();
            $task->setAccount($account);
            $task->setSubject('Test Task for '.$account->getName());
            $manager->persist($task);
            $result = $this->compiler->compile($task);
            $queue->add($result);
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

        /** @var Account $account */
        foreach ($accounts as $account) {
            $account->setName('Update Contact '.$account->getId());
            $result = $this->compiler->compile($account);
            $queue->add($result);
        }

        return $accounts instanceof Collection ? $accounts->toArray() : $accounts;
    }

    private function updateContacts(ArrayCollection $queue)
    {
        $manager  = $this->doctrine->getManager();
        $contacts = $manager->getRepository(Contact::class)->findBy([], null, 400);

        /** @var Contact $contact */
        foreach ($contacts as $contact) {
            $contact->setFirstName('Updated');
            $result = $this->compiler->compile($contact);
            $queue->add($result);
        }

        return $contacts instanceof Collection ? $contacts->toArray() : $contacts;
    }

    private function deleteContacts(ArrayCollection $queue)
    {
        $manager = $this->doctrine->getManager();
        /** @var Contact $contacts */
        $contacts = $manager->getRepository(Contact::class)->findBy([], null, 100, 400);

        foreach ($contacts as $contact) {
            $manager->remove($contact);
            $result = $this->compiler->compile($contact);
            $queue->add($result);
        }
    }
}
