<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/18/19
 * Time: 3:52 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\StringLengthTransformer;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Tests\Entity\Account;

class StringMaxLengthTransformerTest extends AbstractTransformerTest
{
    public function testOutbound()
    {
        $connection = $this->connectionManager->getConnection();
        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);
        $account = new Account();
        $account->setName(random_bytes(265));

        $this->assertEquals(265, strlen($account->getName()));

        $payload = TransformerPayload::outbound()
            ->setMetadata($metadata)
            ->setValue($account->getName())
            ->setEntity($account)
            ->setFieldName('Name')
            ->setPropertyName('name')
            ->setFieldMetadata($metadata->getMetadataForField('Name'))
            ->setClassMetadata($this->registry->getManager()->getClassMetadata(Account::class))
        ;

        $transformer = new StringLengthTransformer();

        $this->assertTrue($transformer->supports($payload));
        $transformer->transform($payload);

        $this->assertEquals(255, strlen($payload->getValue()));
        $this->assertEquals(substr($account->getName(), 0, 255), $payload->getValue());
    }

    public function testInbound()
    {
        // No inbound test
        $this->assertTrue(true);
    }
}
