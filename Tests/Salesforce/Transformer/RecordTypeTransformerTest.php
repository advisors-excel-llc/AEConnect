<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 5:05 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\RecordTypeTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\ConnectBundle\Tests\KernelTestCase;
use AE\SalesforceRestSdk\Model\SObject;

class RecordTypeTransformerTest extends AbstractTransformerTest
{
    public function testOutbound()
    {
        $entity = new TestObject();
        $entity->setRecordType('FunctionalTest');
        $entity->setName('Test Object');

        $metadata      = $this->connectionManager->getConnection()
                                                 ->getMetadataRegistry()
                                                 ->findMetadataByClass(TestObject::class)
        ;
        $fieldMetadata = $metadata->getRecordType();
        $payload       = TransformerPayload::outbound();
        $payload->setFieldName($fieldMetadata->getField());
        $payload->setPropertyName($fieldMetadata->getProperty());
        $payload->setValue($fieldMetadata->getValueFromEntity($entity));
        $payload->setFieldMetadata($fieldMetadata->describe());
        $payload->setEntity($entity);
        $payload->setMetadata($metadata);

        $transformer = new RecordTypeTransformer();

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);

        $recordTypeId = $payload->getValue();
        $this->assertNotEquals('FunctionalTest', $recordTypeId);
        $this->assertEquals(18, strlen($recordTypeId));
    }

    public function testInbound()
    {
        $metadata     = $this->connectionManager->getConnection()
                                                ->getMetadataRegistry()
                                                ->findMetadataByClass(TestObject::class)
        ;
        $recordTypeId = $metadata->getRecordTypeId('FunctionalTest');

        $this->assertNotNull($recordTypeId);

        $sObject = new SObject(
            [
                'Name'         => 'Test Object',
                'Type'         => 'S3F__Test_Object__c',
                'RecordTypeId' => $recordTypeId,
            ]
        );

        $fieldMetadata = $metadata->getRecordType();
        $payload       = TransformerPayload::inbound();
        $payload->setFieldName($fieldMetadata->getField());
        $payload->setPropertyName($fieldMetadata->getProperty());
        $payload->setValue($sObject->RecordTypeId);
        $payload->setFieldMetadata($fieldMetadata->describe());
        $payload->setMetadata($metadata);
        $payload->setEntity($sObject);

        $transformer = new RecordTypeTransformer();

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertEquals('FunctionalTest', $payload->getValue());
    }
}
