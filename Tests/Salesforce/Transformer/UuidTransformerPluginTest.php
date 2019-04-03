<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/3/19
 * Time: 10:34 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\UuidTransformerPlugin;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\SalesforceRestSdk\Model\SObject;
use Ramsey\Uuid\Uuid;

class UuidTransformerPluginTest extends AbstractTransformerTest
{
    public function testOutbound()
    {
        $extId   = Uuid::uuid4();
        $account = new Account();
        $account->setExtId($extId);

        $connection    = $this->connectionManager->getConnection();
        $metadata      = $connection->getMetadataRegistry()->findMetadataForEntity($account);
        $classMetadata = $this->registry->getManager()->getClassMetadata(Account::class);

        $payload = TransformerPayload::outbound()
                                     ->setValue($account->getExtId())
                                     ->setEntity($account)
                                     ->setMetadata($metadata)
                                     ->setFieldName('S3F__HCID__c')
                                     ->setFieldMetadata($metadata->getMetadataForField('S3F__HCID__c'))
                                     ->setPropertyName('extId')
                                     ->setClassMetadata($classMetadata)
        ;

        $transformer = new UuidTransformerPlugin();
        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);

        $this->assertEquals($extId->toString(), $payload->getValue());
    }

    public function testInbound()
    {
        $extId = Uuid::uuid4()->toString();
        $account = new SObject([
            'S3F__HCID__c' => $extId
        ]);

        $connection    = $this->connectionManager->getConnection();
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $classMetadata = $this->registry->getManager()->getClassMetadata(Account::class);

        $payload = TransformerPayload::inbound()
                                     ->setValue($extId)
                                     ->setEntity($account)
                                     ->setMetadata($metadata)
                                     ->setFieldName('S3F__HCID__c')
                                     ->setFieldMetadata($metadata->getMetadataForField('S3F__HCID__c'))
                                     ->setPropertyName('extId')
                                     ->setClassMetadata($classMetadata)
        ;

        $transformer = new UuidTransformerPlugin();
        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);

        $this->assertEquals(Uuid::fromString($extId), $payload->getValue());
    }
}
