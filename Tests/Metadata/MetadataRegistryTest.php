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
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\ConnectBundle\Tests\KernelTestCase;

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
            ['sfid' => 'Id', 'name' => 'Name', 'extId' => 'S3F__hcid__c', 'testPicklist' => 'S3F__Test_Picklist__c'],
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
}
