<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/2/19
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Tests\Doctrine;

use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Util\ConnectionFinder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Driver\Connection;
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

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->entityLocater     = $this->get(EntityLocater::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
        $this->connectionFinder  = $this->get(ConnectionFinder::class);

        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM salesforce_id");
        $conn->exec("DELETE FROM account");
    }

    public function testDefault()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();

        $account = new Account();
        $account->setName('Test Account For Finding');
        $account->setExtId($extId);
        $account->setConnection($connection->getName());
        $account->setSfid('000111000111000');

        $manager->persist($account);
        $manager->flush();

        $foundBySfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id' => '000111000111000',
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
                    'Id'           => '000111000111000',
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
                    'Id'           => '000111000111000',
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingSfid);

        $account->setSfid('000111000111000');
        $account->setExtId(Uuid::uuid4());

        $manager->flush();

        $foundWithMissingExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => '000111000111000',
                    'S3F__HCID__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingExtId);
    }

    public function testDB()
    {
        $connection = $this->connectionManager->getConnection('db_test_org1');
        $metadata   = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $org        = $this->connectionFinder->find($connection->getName(), $metadata);
        $manager    = $this->doctrine->getManager();
        $extId      = Uuid::uuid4();

        $account = new Account();
        $account->setName('Test Account For Finding');
        $account->setExtId($extId);
        $account->setConnections(new ArrayCollection([$org]));

        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId('000111000111000');
        $manager->persist($sfid);

        $account->setSfids(new ArrayCollection([$sfid]));

        $manager->persist($account);
        $manager->flush();

        $foundBySfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id' => '000111000111000',
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
                    'Id'           => '000111000111000',
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundByBoth);

        foreach ($account->getSfids() as $sfid) {
            $manager->remove($sfid);
            $manager->flush();
        }
        $account->setSfids(new ArrayCollection());
        $manager->merge($account);
        $manager->flush();

        $foundWithMissingSfid = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => '000111000111000',
                    'AE_Connect_Id__c' => $extId->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingSfid);

        $sfid = new SalesforceId();
        $sfid->setConnection($org);
        $sfid->setSalesforceId('000111000111000');
        $manager->persist($sfid);

        $account->getSfids()->add($sfid);
        $newExt = Uuid::uuid4();
        $account->setExtId($newExt);

        $manager->flush();

        $foundWithMissingExtId = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => '000111000111000',
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithMissingExtId);

        $foundWithBadSFID = $this->entityLocater->locate(
            new SObject(
                [
                    'Id'           => '000111000111002',
                    'AE_Connect_Id__c' => $newExt->toString(),
                ]
            ),
            $metadata
        );

        $this->assertNotNull($foundWithBadSFID);
    }

    protected function tearDown()
    {
        parent::tearDown();
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec("DELETE FROM salesforce_id");
        $conn->exec("DELETE FROM account");
    }
}
