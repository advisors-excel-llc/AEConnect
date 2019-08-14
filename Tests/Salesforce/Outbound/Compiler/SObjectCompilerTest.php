<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 5:39 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Outbound\Compiler;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

class SObjectCompilerTest extends DatabaseTestCase
{
    use ArraySubsetAsserts;

    /**
     * @var SObjectCompiler
     */
    private $compiler;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler          = $this->get(SObjectCompiler::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection($this->doctrine->getDefaultConnectionName());
        $conn->exec('DELETE FROM account');
        $conn->exec('DELETE FROM contact');
        parent::tearDown();
    }

    public function testInsert()
    {
        $manager = $this->doctrine->getManager();
        $account = new Account();
        $account->setName('Test Account');
        $account->setCreatedDate(new \DateTime());

        $manager->persist($account);

        $contact = new Contact();
        $contact->setFirstName('Testy');
        $contact->setLastName('McTesterson');
        $contact->setAccount($account);

        $manager->persist($contact);

        $accountResult = $this->compiler->compile($account);
        $this->assertEquals(CompilerResult::INSERT, $accountResult->getIntent());
        $this->assertNotNull($accountResult->getReferenceId());

        $metadata = $this->connectionManager->getConnection($accountResult->getConnectionName())
                                            ->getMetadataRegistry()
                                            ->findMetadataByClass($accountResult->getClassName())
        ;
        $this->assertEquals('Account', $metadata->getSObjectType());
        $this->assertEquals(Account::class, $metadata->getClassName());
        $this->assertEquals(['extId' => 'S3F__hcid__c'], $metadata->getIdentifyingFields());
        $this->assertEquals(
            [
                'name'         => 'Name',
                'extId'        => 'S3F__hcid__c',
                'sfid'         => 'Id',
                'testPicklist' => 'S3F__Test_Picklist__c',
                'createdDate'  => 'CreatedDate'
            ],
            $metadata->getPropertyMap()
        );

        $sObject = $accountResult->getSObject();
        $this->assertNotNull($sObject);
        $this->assertEquals('Test Account', $sObject->Name);
        $this->assertEquals('Account', $sObject->getType());
        $this->assertArraySubset(
            [
                'Id',
                'S3F__hcid__c',
                'Name',
            ],
            array_keys($sObject->getFields())
        );

        $contactResult = $this->compiler->compile($contact);
        $this->assertEquals(CompilerResult::INSERT, $contactResult->getIntent());
        $this->assertNotNull($contactResult->getReferenceId());

        $metadata = $this->connectionManager->getConnection($contactResult->getConnectionName())
                                            ->getMetadataRegistry()
                                            ->findMetadataByClass($contactResult->getClassName())
        ;
        $this->assertEquals('Contact', $metadata->getSObjectType());
        $this->assertEquals(Contact::class, $metadata->getClassName());
        $this->assertEquals(['extId' => 'S3F__hcid__c'], $metadata->getIdentifyingFields());
        $this->assertEquals(
            [
                'firstName' => 'FirstName',
                'lastName'  => 'LastName',
                'account'   => 'AccountId',
                'extId'     => 'S3F__hcid__c',
                'sfid'      => 'Id',
                'name'      => 'Name',
            ],
            $metadata->getPropertyMap()
        );

        $sObject = $contactResult->getSObject();
        $this->assertNotNull($sObject);
        $this->assertEquals('Testy', $sObject->FirstName);
        $this->assertEquals('McTesterson', $sObject->LastName);
        $this->assertEquals('Contact', $sObject->getType());
        $this->assertInstanceOf(ReferencePlaceholder::class, $sObject->AccountId);
    }

    public function testUpdate()
    {
        $manager = $this->doctrine->getManager();
        $extId   = Uuid::uuid4();
        $sfid    = SfidGenerator::generate();
        $account = new Account();
        $account->setName('Test Update')
                ->setConnection('default')
                ->setSfid($sfid)
                ->setExtId($extId)
                ->setCreatedDate(new \DateTime())
        ;

        $manager->persist($account);
        $manager->flush();

        $account->setName('Test Update Name')
        ;
        $manager->merge($account);

        $compiled = $this->compiler->compile($account);

        $this->assertEquals(CompilerResult::UPDATE, $compiled->getIntent());
        $this->assertEquals(Account::class, $compiled->getClassName());
        $this->assertEquals('default', $compiled->getConnectionName());

        $object = $compiled->getSObject();

        $this->assertArraySubset(
            [
                'Id',
                'S3F__hcid__c',
                'Name',
            ],
            array_keys($object->getFields())
        );
        $this->assertEquals('Test Update Name', $object->Name);
        $this->assertEquals($extId->toString(), $object->S3F__hcid__c);
        $this->assertEquals($sfid, $object->Id);
        $this->assertNull($object->S3F__Test_Picklist__c);

        $manager->remove($account);
        $manager->flush();
    }

    public function testDelete()
    {
        $manager = $this->doctrine->getManager();
        $extId   = Uuid::uuid4();
        $sfid    = SfidGenerator::generate();
        $account = new Account();
        $account->setName('Test Delete')
                ->setConnection('default')
                ->setSfid($sfid)
                ->setExtId($extId)
                ->setCreatedDate(new \DateTime())
        ;

        $manager->persist($account);
        $manager->flush();

        $manager->remove($account);

        $result = $this->compiler->compile($account, 'default');
        $manager->flush();

        $this->assertEquals(CompilerResult::DELETE, $result->getIntent());
        $this->assertEquals($sfid, $result->getSObject()->Id);
    }

    public function testDeleteNoSfid()
    {
        $this->expectException(\RuntimeException::class);
        $manager = $this->doctrine->getManager();
        $extId   = Uuid::uuid4();
        $account = new Account();
        $account->setName('Test Delete No Sfid')
                ->setConnection('default')
                ->setExtId($extId)
                ->setCreatedDate(new \DateTime())
        ;

        $manager->persist($account);
        $manager->flush();

        $manager->remove($account);
        $this->compiler->compile($account, 'default');
    }
}
