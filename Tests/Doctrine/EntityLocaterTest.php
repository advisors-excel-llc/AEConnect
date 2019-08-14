<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/2/19
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Tests\Doctrine;

use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Util\ConnectionFinder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\Product;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\ConnectBundle\Tests\Entity\Task;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\IdentityGenerator;
use Ramsey\Uuid\Uuid;

class EntityLocaterTest extends DatabaseTestCase
{
    /**
     * @var EntityLocater
     */
    private $entityLocater;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var ConnectionFinder
     */
    private $connectionFinder;

    /**
     * @var OrgConnection
     */
    private $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityLocater     = $this->get(EntityLocater::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
        $this->connectionFinder  = $this->get(ConnectionFinder::class);

        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM salesforce_id");
        $conn->exec("DELETE FROM account");
        $conn->exec("DELETE FROM product");
        $conn->exec("DELETE FROM task");
        $conn->exec("DELETE FROM account_org_connection");
        $conn->exec("DELETE FROM account_salesforce_id");
        $conn->exec("DELETE FROM org_connection");

        /** @var EntityManager $manager */
        $manager   = $this->doctrine->getManager();
        $this->org = $org = new OrgConnection();
        $org->setName('db_test_org1')
            ->setActive(true)
            ->setUsername('test_user1@example.com')
            ->setPassword(1234)
        ;

        $manager->persist($org);
        $manager->flush();

        /** @var DbalConnectionDriver $dbalDriver */
        $dbalDriver = $this->get(DbalConnectionDriver::class);
        $dbalDriver->loadConnections();
    }

    public function testDefault()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();
        $sfid       = SfidGenerator::generate();
        $account    = new Account();
        $account->setName('Test Account For Finding');
        $account->setExtId($extId);
        $account->setConnection($connection->getName());
        $account->setSfid($sfid);

        $manager->persist($account);
        $manager->flush();

        $foundBySfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id' => $sfid,
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundBySfid);

        $foundByExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByExtId);

        $foundByBoth = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => $sfid,
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByBoth);

        $account->setSfid(null);
        $manager->flush();

        $foundWithMissingSfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => $sfid,
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingSfid);

        $account->setSfid($sfid);
        $account->setExtId(Uuid::uuid4());

        $manager->flush();

        $foundWithMissingExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => $sfid,
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingExtId);
    }

    public function testDefaultNoConnection()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Task::class);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();
        $sfid       = SfidGenerator::generate();

        $task = new Task();
        $task->setStatus('Pending')
             ->setSubject('Test Task 1')
             ->setExtId($extId)
             ->setSfid($sfid)
        ;

        $manager->persist($task);
        $manager->flush();

        $found = $this->entityLocater->locate(
            new SObject(
                [
                    'S3F__HCID__c' => $extId->toString(),
                    'Id'           => $sfid,
                    'Name'         => 'Diff Name',
                ]
            ),
            $metadata
        );

        $this->assertNotNull($found);
        $this->assertEquals('Test Task 1', $found->getSubject());
    }

    public function testDefaultNoConnectionBadSfid()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Task::class);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();
        $sfid       = SfidGenerator::generate();

        $task = new Task();
        $task->setStatus('Pending')
             ->setSubject('Test Task 1')
             ->setExtId($extId)
             ->setSfid($sfid)
        ;

        $manager->persist($task);
        $manager->flush();

        $found = $this->entityLocater->locate(
            new SObject(
                [
                    'S3F__HCID__c' => $extId->toString(),
                    'Id'           => SfidGenerator::generate(),
                    'Name'         => 'Diff Name',
                ]
            ),
            $metadata
        );

        $this->assertNotNull($found);
        $this->assertEquals('Test Task 1', $found->getSubject());
    }

    public function testDefaultNoConnectionBadExtId()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Task::class);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();
        $sfid       = SfidGenerator::generate();

        $task = new Task();
        $task->setStatus('Pending')
             ->setSubject('Test Task 1')
             ->setExtId($extId)
             ->setSfid($sfid)
        ;

        $manager->persist($task);
        $manager->flush();

        $found = $this->entityLocater->locate(
            new SObject(
                [
                    'S3F__HCID__c' => Uuid::uuid4()->toString(),
                    'Id'           => $sfid,
                    'Name'         => 'Diff Name',
                ]
            ),
            $metadata
        );

        $this->assertNotNull($found);
        $this->assertEquals('Test Task 1', $found->getSubject());
    }

    public function testDB()
    {
        $org        = $this->org;
        $manager    = $this->doctrine->getManager();
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);

        $extId = Uuid::uuid4();
        $id    = SfidGenerator::generate(true, $metadata);

        $account = new Account();
        $account->setName('Test Account For Finding');
        $account->setExtId($extId);
        $account->setConnections(new ArrayCollection([$org]));

        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId($id);
        $manager->persist($sfid);

        $account->setSfids(new ArrayCollection([$sfid]));

        $manager->persist($account);
        $manager->flush();

        $foundBySfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id' => $id,
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundBySfid);

        $foundByExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByExtId);

        $foundByBoth = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByBoth);
    }

    public function testDBNoSfid()
    {
        $org        = $this->org;
        $manager    = $this->getManager();
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);

        $extId = Uuid::uuid4();
        $id    = SfidGenerator::generate();

        $account = new Account();
        $account->setName('Test Account For Finding');
        $account->setExtId($extId);
        $account->setConnections(new ArrayCollection([$org]));

        $manager->persist($account);
        $manager->flush();

        $foundWithMissingSfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingSfid);
    }

    public function testDBNoExtId()
    {
        $org        = $this->org;
        $manager    = $this->getManager();
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);

        $extId  = Uuid::uuid4();
        $newExt = Uuid::uuid4();

        $account = new Account();
        $account->setName('Test Account For Finding');
        $account->setConnections(new ArrayCollection([$org]));
        $account->setExtId($extId);

        $manager->persist($account);

        $id   = SfidGenerator::generate(true, $metadata);
        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId($id);
        $manager->persist($sfid);

        $account->setSfids(new ArrayCollection([$sfid]));

        $manager->flush();

        $foundWithMissingExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingExtId);

        $foundWithBadSFID = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => SfidGenerator::generate(true, $metadata),
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithBadSFID);
    }

    public function testDBNoConn()
    {
        $org        = $this->org;
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Product::class);
        /** @var EntityManager $manager */
        $manager = $this->getManager();
        $extId   = Uuid::uuid4();
        $id      = SfidGenerator::generate();

        $product = new Product();
        $product->setName('Test Product For Finding');
        $product->setExtId($extId);
        $product->setActive(true);

        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId($id);
        $manager->persist($sfid);

        $product->setSfids(new ArrayCollection([$sfid]));

        $manager->persist($product);
        $manager->flush();

        $foundBySfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id' => $id,
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundBySfid);

        $foundByExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByExtId);

        $foundByBoth = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByBoth);
    }

    public function testDBNoConnFoundWithMissing()
    {
        $org        = $this->org;
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Product::class);
        /** @var EntityManager $manager */
        $manager = $this->getManager();
        $extId   = Uuid::uuid4();
        $id      = SfidGenerator::generate();

        $product = new Product();
        $product->setName('Test Product For Finding');
        $product->setExtId($extId);
        $product->setActive(true);
        $product->setSfids(new ArrayCollection());
        $manager->persist($product);
        $manager->flush();

        $foundWithMissingSfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingSfid);

        $id   = SfidGenerator::generate();
        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId($id);
        $manager->persist($sfid);

        $product->getSfids()->add($sfid);
        $newExt = Uuid::uuid4();
        $product->setExtId($newExt);

        $manager->flush();

        $foundWithMissingExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => $id,
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingExtId);

        $foundWithBadSFID = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'               => SfidGenerator::generate(),
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithBadSFID);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM salesforce_id");
        $conn->exec("DELETE FROM account");
        $conn->exec("DELETE FROM product");
        $conn->exec("DELETE FROM task");
        $conn->exec("DELETE FROM account_salesforce_id");
        $conn->exec("DELETE FROM account_org_connection");
        $conn->exec("DELETE FROM org_connection");
    }
}
