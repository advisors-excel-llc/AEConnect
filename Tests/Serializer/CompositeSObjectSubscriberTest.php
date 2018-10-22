<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 10:04 AM
 */

namespace AE\ConnectBundle\Tests\Serializer;

use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\ConnectBundle\Tests\KernelTestCase;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use JMS\Serializer\SerializerInterface;

class CompositeSObjectSubscriberTest extends KernelTestCase
{
    /** @var SerializerInterface */
    private $serializer;

    protected function setUp()
    {
        parent::setUp();
        $this->serializer = $this->get('jms_serializer');
    }

    public function testReferencePlaceholderDeserialization()
    {
        $object = new CompositeSObject('Account', [
            'testField' => new ReferencePlaceholder('refIdForEntity', 'id')
        ]);

        $data = $this->serializer->serialize($object, 'json');

        $deserialized = $this->serializer->deserialize($data, CompositeSObject::class, 'json');

        $this->assertInstanceOf(ReferencePlaceholder::class, $deserialized->TestField);
        $this->assertEquals('refIdForEntity', $deserialized->testField->getEntityRefId());
    }


}
