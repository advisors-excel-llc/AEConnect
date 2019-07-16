<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 5:21 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\AssociationTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Util\SfidFinder;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\BaseTestType;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\OrgConnection;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\ConnectBundle\Tests\Entity\TestMultiMapType1;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AssociationTransformerTest extends DatabaseTestCase
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;


    protected function setUp()
    {
        parent::setUp();
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     */
    public function testOutbound()
    {
        /** @var EntityManager $manager */
        $manager = $this->doctrine->getManager();

        $account = new Account();
        $account->setName('Test Account');
        $account->setSfid('111000111000111AAA');

        $manager->persist($account);

        $contact = new Contact();
        $contact->setFirstName('Test');
        $contact->setLastName('Contact');
        $contact->setAccount($account);

        $manager->persist($contact);

        $metadata      = $this->connectionManager->getConnection()
                                                 ->getMetadataRegistry()
                                                 ->findMetadataByClass(Contact::class)
        ;
        $fieldMetadata = $metadata->getMetadataForProperty('account');
        $payload       = TransformerPayload::outbound()
                                           ->setValue($contact->getAccount())
                                           ->setEntity($contact)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata(Contact::class))
        ;

        $transformer = new AssociationTransformer(
            $this->connectionManager,
            $this->doctrine,
            $this->get(ValidatorInterface::class),
            $this->get(SfidFinder::class)
        );

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertEquals('111000111000111AAA', $payload->getValue());
    }

    public function testOutboundDBTest()
    {
        $this->loadOrgConnections();
        $manager = $this->getDoctrine()->getManager();
        /** @var OrgConnection $conn */
        $conn = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_test_org1']);

        $account = new Account();
        $account->setName('Test Account');
        $account->setConnections(new ArrayCollection([$conn]));
        $accountSfid = new SalesforceId();
        $accountSfid->setConnection($conn)
                    ->setSalesforceId('111000111000111AAA')
        ;
        $account->setSfids(new ArrayCollection([$accountSfid]));

        $manager->persist($account);

        $contact = new Contact();
        $contact->setFirstName('Test');
        $contact->setLastName('Contact');
        $contact->setAccount($account);
        $contact->setConnection($conn);

        $manager->persist($contact);

        $metadata      = $this->connectionManager->getConnection('db_test_org1')
                                                 ->getMetadataRegistry()
                                                 ->findMetadataByClass(Contact::class)
        ;
        $fieldMetadata = $metadata->getMetadataForProperty('account');
        $payload       = TransformerPayload::outbound()
                                           ->setValue($contact->getAccount())
                                           ->setEntity($contact)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata(Contact::class))
        ;

        $transformer = new AssociationTransformer(
            $this->connectionManager,
            $this->doctrine,
            $this->get(ValidatorInterface::class),
            $this->get(SfidFinder::class)
        );

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertEquals('111000111000111AAA', $payload->getValue());
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function testInbound()
    {
        /** @var EntityManager $manager */
        $manager = $this->doctrine->getManager();

        $account = new Account();
        $account->setName('Test Account');
        $account->setSfid(SfidGenerator::generate());

        $manager->persist($account);
        $manager->flush();

        $sObject = new SObject(
            [
                'Id'        => SfidGenerator::generate(),
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
                'AccountId' => $account->getSfid(),
            ]
        );

        $metadatas = $this->connectionManager->getConnection()
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Contact')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForField('AccountId');
        $payload       = TransformerPayload::inbound()
                                           ->setValue($sObject->AccountId)
                                           ->setSObject($sObject)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $transformer = new AssociationTransformer(
            $this->connectionManager,
            $this->doctrine,
            $this->get(ValidatorInterface::class),
            $this->get(SfidFinder::class)
        );

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertInstanceOf(Account::class, $payload->getValue());
        $this->assertEquals($account->getId(), $payload->getValue()->getId());
    }

    public function testInboundDBTest()
    {
        $this->loadOrgConnections();

        $manager = $this->getDoctrine()->getManager();
        /** @var OrgConnection $conn */
        $conn = $manager->getRepository(OrgConnection::class)->findOneBy(['name' => 'db_test_org1']);

        $account = new Account();
        $account->setName('Test Account');
        $account->setConnections(new ArrayCollection([$conn]));
        $accountSfid = new SalesforceId();
        $accountSfid->setConnection($conn)
                    ->setSalesforceId(SfidGenerator::generate())
        ;
        $account->setSfids(new ArrayCollection([$accountSfid]));

        $manager->persist($account);
        $manager->flush();

        $sObject = new SObject(
            [
                'Id'        => SfidGenerator::generate(),
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
                'AccountId' => $accountSfid->getSalesforceId(),
            ]
        );

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Contact')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForField('AccountId');
        $payload       = TransformerPayload::inbound()
                                           ->setValue($sObject->AccountId)
                                           ->setSObject($sObject)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $transformer = new AssociationTransformer(
            $this->connectionManager,
            $this->doctrine,
            $this->get(ValidatorInterface::class),
            $this->get(SfidFinder::class)
        );

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertInstanceOf(Account::class, $payload->getValue());
        $this->assertEquals($account->getId(), $payload->getValue()->getId());
    }

    public function testInboundWithMappedSuperclass()
    {
        $manager    = $this->doctrine->getManagerForClass(BaseTestType::class);
        $connection = $this->connectionManager->getConnection();
        $metadatas  = $connection->getMetadataRegistry()->findMetadataBySObjectType('S3F__Test_Multi_Map__c');
        foreach ($metadatas as $metadata) {
            if ($metadata->getRecordType()->getName() === 'TestType2') {
                break;
            }
        }
        $parent = new TestMultiMapType1();
        $parent->setName('Test Parent 1')
               ->setExtId(Uuid::uuid4())
               ->setSfid(SfidGenerator::generate())
        ;

        $manager->persist($parent);
        $manager->flush();

        $sObject = new SObject(
            [
                'Id'               => SfidGenerator::generate(),
                'S3F__HCID__c'     => Uuid::uuid4(),
                'S3F__Parent__c'   => $parent->getSfid(),
                'Name'             => 'Test Child 1',
                'RecordTypeId'     => $metadata->getRecordTypeId('TestType2'),
                '__SOBJECT_TYPE__' => 'S3F__Test_Multi_Map__c',
            ]
        );

        $fieldMetadata = $metadata->getMetadataForField('S3F__Parent__c');
        $payload       = TransformerPayload::inbound()
                                           ->setValue($sObject->S3F__Parent__c)
                                           ->setSObject($sObject)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $transformer = new AssociationTransformer(
            $this->connectionManager,
            $this->doctrine,
            $this->get(ValidatorInterface::class),
            $this->get(SfidFinder::class)
        );

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $entity = $payload->getValue();
        $this->assertEquals($parent, $entity);
    }
}
