<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/16/19
 * Time: 2:38 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\SfidTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\DatabaseTestTrait;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\ConnectionEntity;
use AE\ConnectBundle\Tests\Entity\Contact;
use AE\ConnectBundle\Tests\Entity\SalesforceId;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Annotations\Reader;
use Faker\Test\Provider\Collection;

class SalesforceIdTransformerTest extends AbstractTransformerTest
{
    use DatabaseTestTrait;

    protected function setUp()
    {
        parent::setUp();
        $this->setLoader(static::$container->get('fidry_alice_data_fixtures.loader.doctrine'));
        $this->setDoctrine(static::$container->get('doctrine'));
        $this->setDbalConnectionDriver(static::$container->get(DbalConnectionDriver::class));
        $this->setProjectDir(static::$container->getParameter('kernel.project_dir'));
        $this->createSchemas();
    }

    public function testInbound()
    {
        $this->loadOrgConnections();
        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

        $transformer = new SfidTransformer($this->getDoctrine(), $this->get(Reader::class));
        $manager     = $this->getDoctrine()->getManager();
        $account     = new SObject(
            [
                'Name' => 'Test Account',
                'Id'   => '111000111000111ADA',
            ]
        );
        $metadatas   = $this->connectionManager->getConnection('db_test_org1')
                                               ->getMetadataRegistry()
                                               ->findMetadataBySObjectType('Account')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForField('Id');
        $payload       = TransformerPayload::inbound()
                                           ->setValue($account->Id)
                                           ->setEntity($account)
                                           ->setFieldName('Id')
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldMetadata($fieldMetadata)
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);
        $value = $payload->getValue() instanceof Collection ? $payload->getValue()->toArray() : $payload->getValue();

        $this->assertNotEmpty($value);
        $this->assertEquals('111000111000111ADA', $value[0]->getSalesforceId());

        // Test Singular
        $contact = new SObject(
            [
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
                'Id'        => '111000111000111AQA',
            ]
        );

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Contact')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata       = $metadatas[0];
        $fieldMetadata  = $metadata->getMetadataForField('Id');
        $contactPayload = TransformerPayload::inbound()
                                            ->setValue($contact->Id)
                                            ->setEntity($contact)
                                            ->setFieldName('Id')
                                            ->setPropertyName($fieldMetadata->getProperty())
                                            ->setFieldMetadata($fieldMetadata)
                                            ->setMetadata($metadata)
                                            ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($contactPayload));
        $transformer->transform($contactPayload);
        $this->assertInstanceOf(SalesforceId::class, $contactPayload->getValue());
        $this->assertEquals('111000111000111AQA', $contactPayload->getValue()->getSalesforceId());
    }

    public function testInboundWithSaved()
    {
        $this->loadOrgConnections();
        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

        $transformer = new SfidTransformer($this->getDoctrine(), $this->get(Reader::class));
        $manager     = $this->getDoctrine()->getManager();
        $conn        = $manager->getRepository(ConnectionEntity::class)->findOneBy(['name' => 'db_test_org1']);
        $account     = new SObject(
            [
                'Name' => 'Test Account',
                'Id'   => '111000111000111ADA',
            ]
        );

        $entity = new Account();
        $entity->setName('Tset');
        $entity->setConnections([$conn]);
        $sfid = new SalesforceId();
        $sfid->setSalesforceId('111000111000111ADA');
        $sfid->setConnection($conn);
        $entity->setSfids([$sfid]);

        $manager->persist($sfid);
        $manager->persist($entity);
        $manager->flush();

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Account')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForField('Id');
        $payload       = TransformerPayload::inbound()
                                           ->setValue($account->Id)
                                           ->setEntity($account)
                                           ->setFieldName('Id')
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldMetadata($fieldMetadata)
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);
        $value = $payload->getValue() instanceof Collection ? $payload->getValue()->toArray() : $payload->getValue();

        $this->assertNotEmpty($value);
        $this->assertEquals('111000111000111ADA', $value[0]->getSalesforceId());
        $this->assertNotNull($value[0]->getId());

        // Test Singular
        $contact = new SObject(
            [
                'FirstName' => 'Test',
                'LastName'  => 'Contact',
                'Id'        => '111000111000111AQA',
            ]
        );

        $entity = new Contact();
        $entity->setFirstName('Tset');
        $entity->setLastName('Ctav');
        $entity->setConnection($conn);
        $sfid = new SalesforceId();
        $sfid->setSalesforceId('111000111000111AQA');
        $sfid->setConnection($conn);
        $entity->setDbTestSfid($sfid);

        $manager->persist($sfid);
        $manager->persist($entity);
        $manager->flush();

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Contact')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata       = $metadatas[0];
        $fieldMetadata  = $metadata->getMetadataForField('Id');
        $contactPayload = TransformerPayload::inbound()
                                            ->setValue($contact->Id)
                                            ->setEntity($contact)
                                            ->setFieldName('Id')
                                            ->setPropertyName($fieldMetadata->getProperty())
                                            ->setFieldMetadata($fieldMetadata)
                                            ->setMetadata($metadata)
                                            ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($contactPayload));
        $transformer->transform($contactPayload);
        $this->assertInstanceOf(SalesforceId::class, $contactPayload->getValue());
        $this->assertEquals('111000111000111AQA', $contactPayload->getValue()->getSalesforceId());
        $this->assertNotNull($contactPayload->getValue()->getId());
    }

    public function testOutbound()
    {
        $this->loadOrgConnections();
        $this->loadFixtures(
            [
                $this->getProjectDir().'/Tests/Resources/config/connections.yml',
            ]
        );

        $transformer = new SfidTransformer($this->getDoctrine(), $this->get(Reader::class));
        $manager     = $this->getDoctrine()->getManager();
        $conn        = $manager->getRepository(ConnectionEntity::class)->findOneBy(['name' => 'db_test_org1']);

        $account = new Account();
        $account->setName('Tset');
        $account->setConnections([$conn]);
        $sfid = new SalesforceId();
        $sfid->setSalesforceId('111000111000111ADA');
        $sfid->setConnection($conn);
        $account->setSfids([$sfid]);

        $manager->persist($sfid);
        $manager->persist($account);
        $manager->flush();

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Account')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForField('Id');
        $payload       = TransformerPayload::outbound()
                                           ->setValue($account->getSfids())
                                           ->setEntity($account)
                                           ->setFieldName('Id')
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldMetadata($fieldMetadata)
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);
        $this->assertEquals('111000111000111ADA', $payload->getValue());

        $contact = new Contact();
        $contact->setFirstName('Tset');
        $contact->setLastName('Ctav');
        $contact->setConnection($conn);
        $sfid = new SalesforceId();
        $sfid->setSalesforceId('111000111000111AQA');
        $sfid->setConnection($conn);
        $contact->setDbTestSfid($sfid);

        $manager->persist($sfid);
        $manager->persist($contact);
        $manager->flush();

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Contact')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata       = $metadatas[0];
        $fieldMetadata  = $metadata->getMetadataForField('Id');
        $contactPayload = TransformerPayload::outbound()
                                            ->setValue($contact->getDbTestSfid())
                                            ->setEntity($contact)
                                            ->setFieldName('Id')
                                            ->setPropertyName($fieldMetadata->getProperty())
                                            ->setFieldMetadata($fieldMetadata)
                                            ->setMetadata($metadata)
                                            ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($contactPayload));
        $transformer->transform($contactPayload);
        $this->assertEquals('111000111000111AQA', $contactPayload->getValue());
    }
}
