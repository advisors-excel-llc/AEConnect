<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 5:46 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\MultiValuePickListTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\SalesforceRestSdk\Model\SObject;

class MutliValuePicklistTest extends AbstractTransformerTest
{
    public function testOutbound()
    {
        $entity = new Account();
        $entity->setTestPicklist(['Item 1', 'Item 2', 'Dog']);

        $metadata = $this->connectionManager->getConnection()
                                            ->getMetadataRegistry()
                                            ->findMetadataByClass(Account::class)
        ;
        $fieldMetadata = $metadata->getMetadataForProperty('testPicklist');
        $payload = TransformerPayload::outbound()
            ->setValue($fieldMetadata->getValueFromEntity($entity))
            ->setEntity($entity)
            ->setPropertyName($fieldMetadata->getProperty())
            ->setFieldName($fieldMetadata->getField())
            ->setFieldMetadata($fieldMetadata)
            ->setMetadata($metadata)
            ->setClassMetadata($this->registry->getManager()->getClassMetadata(Account::class))
            ;

        $transformer = new MultiValuePickListTransformer($this->registry);

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertEquals("Item 1;Item 2", $payload->getValue());
    }

    public function testInbound()
    {
        $sObject = new SObject(
            [
                'Type' => 'Account',
                'S3F__Test_Picklist__c' => "Item 1;Item 2"
            ]
        );

        $metadatas = $this->connectionManager->getConnection()
                                            ->getMetadataRegistry()
                                            ->findMetadataBySObjectType('Account')
        ;
        $this->assertNotEmpty($metadatas);
        $metadata = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForProperty('testPicklist');
        $payload = TransformerPayload::inbound()
                                     ->setValue($sObject->S3F__Test_Picklist__c)
                                     ->setEntity($sObject)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setFieldName($fieldMetadata->getField())
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setMetadata($metadata)
                                     ->setClassMetadata(
                                         $this->registry->getManager()->getClassMetadata($metadata->getClassName())
                                     )
        ;

        $transformer = new MultiValuePickListTransformer($this->registry);

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertEquals(["Item 1", "Item 2"], $payload->getValue());
    }
}
