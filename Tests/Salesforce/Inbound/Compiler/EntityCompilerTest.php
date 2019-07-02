<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/18/19
 * Time: 12:11 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Salesforce\Inbound\Compiler\EntityCompiler;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\ArrayCollection;
use Ramsey\Uuid\Uuid;

class EntityCompilerTest extends DatabaseTestCase
{
    /**
     * @var EntityCompiler
     */
    private $entityCompiler;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->entityCompiler = $this->get(EntityCompiler::class);
    }

    public function testFindEntity()
    {
        $this->loadOrgConnections();
        $manager = $this->getDoctrine()->getManager();
        /** @var OrgConnection $conn */
        $conn        = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_test_org1']);
        $accountSfid = new SalesforceId();
        $accountSfid->setConnection($conn)
                    ->setSalesforceId('111000111000111ADA')
        ;
        $accountUuid = Uuid::uuid4();

        $account = new Account();
        $account->setName('New Test Account')
                ->setExtId($accountUuid)
                ->setConnections(new ArrayCollection([$conn]))
        ;
        $manager->persist($account);

        $oldAccountUuid = Uuid::uuid4();
        $oldAccount     = new Account();
        $oldAccount->setName('Old Test Account')
                   ->setConnections(new ArrayCollection([$conn]))
                   ->setExtId($oldAccountUuid)
                   ->setSfids([$accountSfid])
        ;
        $manager->persist($oldAccount);

        $manager->flush();

        $naObj = new SObject(
            [
                'Id'               => '111000111000111ADF',
                'Name'             => 'New Test Account Updated',
                'AE_Connect_Id__c' => $accountUuid->toString(),
                '__SOBJECT_TYPE__' => 'Account',
            ]
        );

        $entities = $this->entityCompiler->compile($naObj, $conn->getName());
        $entity   = array_shift($entities);

        $this->assertNotNull($entity);
        $this->assertInstanceOf(Account::class, $entity);
        $this->assertEquals($account->getId(), $entity->getId());
        $this->assertEquals('New Test Account Updated', $entity->getName());

        $oaObj = new SObject(
            [
                'Id'               => '111000111000111ADA',
                'Name'             => 'Old Test Account Updated',
                'AE_Connect_Id__c' => $oldAccountUuid->toString(),
                '__SOBJECT_TYPE__' => 'Account',
            ]
        );

        $entities = $this->entityCompiler->compile($oaObj, $conn->getName());
        $entity   = array_shift($entities);

        $this->assertNotNull($entity);
        $this->assertInstanceOf(Account::class, $entity);
        $this->assertEquals($oldAccount->getId(), $entity->getId());
        $this->assertEquals('Old Test Account Updated', $entity->getName());
    }
}
