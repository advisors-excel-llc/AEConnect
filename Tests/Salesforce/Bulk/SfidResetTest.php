<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 10:24 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\SfidReset;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\AltProduct;
use AE\ConnectBundle\Tests\Entity\AltSalesforceId;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;

class SfidResetTest extends DatabaseTestCase
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SfidReset
     */
    private $sfidReset;

    private $entities = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionManager    = $this->get(ConnectionManagerInterface::class);
        $this->sfidReset            = $this->get(SfidReset::class);

        $connection = $this->doctrine->getConnection();
        $connection->exec('DELETE FROM "order_table"');
        $connection->exec('DELETE FROM account');
        $connection->exec('DELETE FROM account_salesforce_id');
        $connection->exec('DELETE FROM product');
        $connection->exec('DELETE FROM product_salesforce_id');
        $connection->exec('DELETE FROM alt_product_alt_salesforce_id');
        $connection->exec('DELETE FROM salesforce_id');
        $connection->exec('DELETE FROM alt_salesforce_id');
    }

    public function testClearIds()
    {
        $this->dbalConnectionDriver->loadConnections();
        $manager        = $this->doctrine->getManager();
        $orgConnections = $manager->getRepository(OrgConnection::class)->findAll();

        $account = new Account();
        $account->setName('Test Account Clear Ids')
            ->setConnection('default')
            ->setSfid(SfidGenerator::generate())
            ;

        foreach ($orgConnections as $connection) {
            $sfid = new SalesforceId();
            $sfid->setConnection($connection)
                ->setSalesforceId(SfidGenerator::generate())
                ;
            $account->getSfids()->add($sfid);
        }

        /** @var Account $account */
        $account = $manager->merge($account);
        $manager->flush();
        $accountSfid = $account->getSfid();
        $prevAccSfids = $account->getSfids();

        $product = new Product();
        $product->setActive(true)
                ->setName('Testo Producto')
        ;

        foreach ($orgConnections as $connection) {
            $sfid = new SalesforceId();
            $sfid->setConnection($connection)
                 ->setSalesforceId(SfidGenerator::generate())
            ;

            $product->getSfids()->add($sfid);
        }

        /** @var Product $product */
        $product = $manager->merge($product);
        $manager->flush();
        $this->entities[] = $product;
        $prevProdSfids = $product->getSfids()->toArray();

        $this->assertCount(count($orgConnections), $prevProdSfids);

        $altProduct = new AltProduct();
        $altProduct->setName('Testo Alto Producto')
            ->setActive(true)
            ;

        foreach ($orgConnections as $connection) {
            $sfid = new AltSalesforceId();
            $sfid->setConnection($connection->getName())
                ->setSalesforceId(SfidGenerator::generate())
            ;
            $altProduct->getSfids()->add($sfid);
        }

        $sfid = new AltSalesforceId();
        $sfid->setConnection('default')
            ->setSalesforceId(SfidGenerator::generate())
            ;

        $altProduct->getSfids()->add($sfid);

        /** @var AltProduct $altProduct */
        $altProduct = $manager->merge($altProduct);
        $manager->flush();

        $prevAltSfids = $altProduct->getSfids();

        $orderSfid = new AltSalesforceId();
        $orderSfid->setConnection('default')
            ->setSalesforceId(SfidGenerator::generate())
            ;
        /** @var AltSalesforceId $orderSfid */
        $orderSfid = $manager->merge($orderSfid);
        $order = new Order();
        $order->setStatus(Order::DRAFT)
            ->setSfid($orderSfid)
            ->setEffectiveDate(new \DateTime())
        ;

        /** @var Order $order */
        $order = $manager->merge($order);
        $manager->flush();

        /** @var OrgConnection $clearConn */
        $clearConn = $orgConnections[0];
        $connection = $this->connectionManager->getConnection($clearConn->getName());

        $this->assertNotNull($connection);
        $manager->clear();

        $this->sfidReset->clearIds($connection, ['Account', 'Product2', 'Order']);

        /** @var Account $updatedAccount */
        $updatedAccount = $manager->getRepository(Account::class)->find($account->getId());
        $this->assertNotNull($updatedAccount);
        $this->assertNotNull($updatedAccount->getSfid());
        $this->assertEquals($accountSfid, $updatedAccount->getSfid());
        $this->assertCount(count($prevAccSfids) - 1, $updatedAccount->getSfids());

        $accNames = $updatedAccount->getSfids()->map(function (SalesforceId $salesforceId) {
            return $salesforceId->getConnection()->getName();
        });

        $this->assertNotContains($connection->getName(), $accNames);

        /** @var Product $updatedProduct */
        $updatedProduct = $manager->getRepository(Product::class)->find($product->getId());
        $this->assertNotNull($updatedProduct);

        $this->assertCount(count($prevProdSfids) - 1, $updatedProduct->getSfids());

        $prodNames = $updatedProduct->getSfids()->map(function (SalesforceId $salesforceId) {
            return $salesforceId->getConnection()->getName();
        });

        $this->assertNotContains($connection->getName(), $prodNames);

        /** @var AltProduct $updateAltProduct */
        $updateAltProduct = $manager->getRepository(AltProduct::class)->find($altProduct->getId());
        $this->assertNotNull($updateAltProduct);

        $this->assertCount(count($prevAltSfids) - 1, $updateAltProduct->getSfids());

        $altNames = $updateAltProduct->getSfids()->map(function (AltSalesforceId $salesforceId) {
            return $salesforceId->getConnection();
        });

        $this->assertNotContains($connection->getName(), $altNames);

        /** @var Order $updatedOrder */
        $updatedOrder = $manager->getRepository(Order::class)->find($order->getId());
        $this->assertNotNull($updatedOrder);
        $this->assertNotNull($updatedOrder->getSfid());
        $this->assertEquals($orderSfid->getSalesforceId(), $updatedOrder->getSfid()->getSalesforceId());

        $this->sfidReset->clearIds($this->connectionManager->getConnection(), ['Account', 'Order']);

        /** @var Account $updatedAccount */
        $updatedAccount = $manager->getRepository(Account::class)->find($account->getId());
        $this->assertNotNull($updatedAccount);
        $this->assertNull($updatedAccount->getSfid());
        $this->assertNotEmpty($updatedAccount->getSfids());

        /** @var Order $updatedOrder */
        $updatedOrder = $manager->getRepository(Order::class)->find($order->getId());
        $this->assertNotNull($updatedOrder);
        $this->assertNull($updatedOrder->getSfid());
    }

    protected function tearDown()
    {
        $connection = $this->doctrine->getConnection();
        $connection->exec('DELETE FROM "order_table"');
        $connection->exec('DELETE FROM account');
        $connection->exec('DELETE FROM account_salesforce_id');
        $connection->exec('DELETE FROM product');
        $connection->exec('DELETE FROM product_salesforce_id');
        $connection->exec('DELETE FROM alt_product_alt_salesforce_id');
        $connection->exec('DELETE FROM salesforce_id');
        $connection->exec('DELETE FROM alt_salesforce_id');

        parent::tearDown();
    }
}
