<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 1:34 PM
 */

namespace AE\ConnectBundle\Tests\Metadata;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\TestMultiMapType1;
use AE\ConnectBundle\Tests\Entity\TestMultiMapType2;
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\ConnectBundle\Tests\KernelTestCase;
use AE\SalesforceRestSdk\Model\SObject;

class MetadataRegistryTest extends KernelTestCase
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp()
    {
        parent::setUp();

        $this->connectionManager = $this->get('ae_connect.connection_manager');
    }

    public function testMetadataRegistry()
    {
        $connection       = $this->connectionManager->getConnection();
        $metadataRegistry = $connection->getMetadataRegistry();

        $this->assertNotNull($metadataRegistry);

        $metadata = $metadataRegistry->getMetadata();
        $this->assertNotEmpty($metadata);

        $metadatum = $metadataRegistry->findMetadataByClass(Account::class);

        $this->assertNotNull($metadatum);

        $this->assertArraySubset(
            [
                'sfid'         => 'Id',
                'name'         => 'Name',
                'extId'        => 'S3F__hcid__c',
                'testPicklist' => 'S3F__Test_Picklist__c',
                'createdDate'  => 'CreatedDate',
            ],
            $metadatum->getPropertyMap()
        );

        $identifiers = $metadatum->getIdentifiers();
        $this->assertNotEmpty($identifiers->toArray());
        $this->assertInstanceOf(FieldMetadata::class, $identifiers->first());
        $this->assertTrue($identifiers->first()->isIdentifier());
        $this->assertEquals('extId', $identifiers->first()->getProperty());
        $this->assertEquals('S3F__hcid__c', $identifiers->first()->getField());

        $describe = $metadatum->getDescribe();

        $this->assertNotNull($describe);
        $this->assertNotNull($describe->getName());
        $this->assertEquals($metadatum->getSObjectType(), $describe->getName());
        $this->assertNotEmpty($describe->getFields());
        $this->assertNotNull($metadatum->getConnectionNameField());
        $this->assertEquals("connection", $metadatum->getConnectionNameField()->getProperty());
    }

    public function testMetadataWithRecordType()
    {
        $connection       = $this->connectionManager->getConnection();
        $metadataRegistry = $connection->getMetadataRegistry();

        $metadatum = $metadataRegistry->findMetadataByClass(TestObject::class);

        $this->assertArraySubset(
            ['sfid' => 'Id', 'name' => 'Name', 'extId' => 'S3F__hcid__c', 'recordType' => 'RecordTypeId'],
            $metadatum->getPropertyMap()
        );

        $this->assertNotNull($metadatum->getRecordType());
        $recordType = $metadatum->getRecordType();

        $entity = new TestObject();
        $entity->setRecordType('FunctionalTest');

        $recordTypeName = $recordType->getValueFromEntity($entity);

        $this->assertEquals('FunctionalTest', $recordTypeName);

        $recordType->setValueForEntity($entity, 'UnitTest');

        $this->assertEquals('UnitTest', $entity->getRecordType());

        $recordType->setGetter(null);
        $recordType->setSetter(null);
        $recordType->setProperty('recordType');

        $recordTypeName = $recordType->getValueFromEntity($entity);

        $this->assertEquals('UnitTest', $recordTypeName);

        $recordType->setValueForEntity($entity, 'FunctionalTest');

        $this->assertEquals('FunctionalTest', $entity->getRecordType());

        $recordType->setName('TestType');
        $recordTypeName = $recordType->getValueFromEntity($entity);

        $this->assertEquals('TestType', $recordTypeName);

        $recordType->setValueForEntity($entity, 'FunctionalTest');

        $recordTypeName = $recordType->getValueFromEntity($entity);

        $this->assertEquals('TestType', $recordTypeName);
    }

    public function testFindMetadataBySObject()
    {
        $connection       = $this->connectionManager->getConnection();
        $metadataRegistry = $connection->getMetadataRegistry();
        $metadata1        = $metadataRegistry->findMetadataByClass(TestMultiMapType1::class);
        $recordType1      = $metadata1->getRecordTypeId($metadata1->getRecordType()->getName());
        $metadata2        = $metadataRegistry->findMetadataByClass(TestMultiMapType2::class);
        $recordType2      = $metadata2->getRecordTypeId($metadata2->getRecordType()->getName());

        $object = new SObject(
            [
                '__SOBJECT_TYPE__' => 'S3F__Test_Multi_Map__c',
                'RecordTypeId'     => $recordType1,
                'Name'             => 'Test Multi Map',
            ]
        );

        $meta1 = $metadataRegistry->findMetadataBySObject($object);
        $this->assertCount(1, $meta1);
        $meta1 = $meta1[0];

        $this->assertEquals(TestMultiMapType1::class, $meta1->getClassName());

        $object->RecordTypeId = $recordType2;

        $meta2 = $metadataRegistry->findMetadataBySObject($object);
        $this->assertCount(1, $meta2);
        $meta2 = $meta2[0];

        $this->assertEquals(TestMultiMapType2::class, $meta2->getClassName());
    }
}
