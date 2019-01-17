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

class SObjectCompilerTest extends DatabaseTestCase
{
    /**
     * @var SObjectCompiler
     */
    private $compiler;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp()
    {
        parent::setUp();

        $this->compiler          = $this->get(SObjectCompiler::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    public function testInsert()
    {
        $manager = $this->doctrine->getManager();
        $account = new Account();
        $account->setName('Test Account');

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
            ],
            $metadata->getPropertyMap()
        );

        $sObject = $accountResult->getSObject();
        $this->assertNotNull($sObject);
        $this->assertEquals('Test Account', $sObject->Name);
        $this->assertEquals('Account', $sObject->getType());

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
}
