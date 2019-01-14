<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/14/19
 * Time: 10:55 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\DateTimeTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\Entity\Order;
use AE\SalesforceRestSdk\Model\SObject;

class DateTimeTransformerTest extends AbstractTransformerTest
{
    public function testOutbound()
    {
        $order = new Order();
        $date = \DateTime::createFromFormat(\DateTime::ISO8601, '2019-01-14T16:06:35+0000');
        $order->setEffectiveDate($date);

        $metadata = $this->connectionManager->getConnection()
                                            ->getMetadataRegistry()
                                            ->findMetadataByClass(Order::class)
        ;
        $fieldMetadata = $metadata->getMetadataForProperty('effectiveDate');
        $payload = TransformerPayload::outbound()
                                     ->setValue($fieldMetadata->getValueFromEntity($order))
                                     ->setEntity($order)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setFieldName($fieldMetadata->getField())
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setMetadata($metadata)
                                     ->setClassMetadata($this->registry->getManager()->getClassMetadata(Order::class))
        ;

        $transformer = new DateTimeTransformer($this->registry);

        $this->assertEquals(true, $transformer->supports($payload));

        $transformer->transformOutbound($payload);

        $this->assertEquals('2019-01-14T16:06:35+0000', $payload->getValue());
    }

    public function testInbound()
    {
        $sObject = new SObject(
            [
                'Type' => 'Order',
                'EffectiveDate' => "2019-01-14T16:06:35+0000"
            ]
        );

        $metadatas = $this->connectionManager->getConnection()
                                             ->getMetadataRegistry()
                                             ->findMetadataBySObjectType('Order')
        ;
        $this->assertNotEmpty($metadatas);
        $metadata = $metadatas[0];
        $fieldMetadata = $metadata->getMetadataForProperty('effectiveDate');
        $payload = TransformerPayload::inbound()
                                     ->setValue($sObject->EffectiveDate)
                                     ->setEntity($sObject)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setFieldName($fieldMetadata->getField())
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setMetadata($metadata)
                                     ->setClassMetadata(
                                         $this->registry->getManager()->getClassMetadata($metadata->getClassName())
                                     )
        ;

        $transformer = new DateTimeTransformer($this->registry);

        $this->assertTrue($transformer->supports($payload));

        $transformer->transform($payload);

        $this->assertInstanceOf(\DateTime::class, $payload->getValue());
        $this->assertEquals("2019-01-14T16:06:35+0000", $payload->getValue()->format(\DateTime::ISO8601));
    }
}
