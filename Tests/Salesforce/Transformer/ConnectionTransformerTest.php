<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/16/19
 * Time: 12:15 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\ConnectionEntityTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\DatabaseTestTrait;
use AE\ConnectBundle\Tests\Entity\ConnectionEntity;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;

class ConnectionTransformerTest extends AbstractTransformerTest
{
    use DatabaseTestTrait;

    public function setUp()
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

        /** @var EntityManager $manager */
        $manager       = $this->getDoctrine()->getManager();
        $connectEntity = $manager->getRepository(ConnectionEntity::class)->findOneBy(['name' => 'db_test_org1']);
        $transformer   = new ConnectionEntityTransformer(
            $this->getDoctrine(),
            $this->get(Reader::class)
        );

        $this->assertNotNull($connectEntity);

        $account = new SObject(
            [
                'Name' => 'Test Account',
                'Id'   => '111000111000111ADA',
            ]
        );

        $metadatas = $this->connectionManager->getConnection('db_test_org1')
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Account')
        ;

        $this->assertNotEmpty($metadatas);

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getConnectionNameField();
        $payload       = TransformerPayload::inbound()
                                           ->setValue($metadata->getConnectionName())
                                           ->setEntity($account)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldMetadata($fieldMetadata)
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);
        $value = $payload->getValue() instanceof Collection ? $payload->getValue()->toArray() : $payload->getValue();

        $this->assertNotEmpty($value);
        $this->assertEquals('db_test_org1', $value[0]->getName());

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

        $metadata      = $metadatas[0];
        $fieldMetadata = $metadata->getConnectionNameField();
        $contactPayload = TransformerPayload::inbound()
                                            ->setValue($metadata->getConnectionName())
                                            ->setEntity($contact)
                                            ->setPropertyName($fieldMetadata->getProperty())
                                            ->setFieldMetadata($fieldMetadata)
                                            ->setMetadata($metadata)
                                            ->setClassMetadata($manager->getClassMetadata($metadata->getClassName()))
        ;

        $this->assertTrue($transformer->supports($contactPayload));
        $transformer->transform($contactPayload);
        $this->assertInstanceOf(ConnectionEntity::class, $contactPayload->getValue());
        $this->assertEquals('db_test_org1', $contactPayload->getValue()->getName());
    }

    public function testOutbound()
    {
        // No outbound test
        $this->assertTrue(true);
    }
}
