<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/13/18
 * Time: 2:03 PM
 */

namespace AE\ConnectBundle\Tests\Composite\Serializer;

use AE\ConnectBundle\Composite\Model\SObject;
use AE\ConnectBundle\Tests\KernelTestCase;
use JMS\Serializer\SerializerInterface;

class SObjectSerializeHandlerTest extends KernelTestCase
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    protected function setUp()
    {
        parent::setUp();
        $this->serializer = static::$kernel->getContainer()->get("jms_serializer");
    }

    public function testSobjectSerialziationSingle()
    {
        $sobject = new SObject("Account");

        $sobject->Name = 'Test Object';
        $sobject->OwnerId = 'A10500010129302A10';

        $json = $this->serializer->serialize($sobject, 'json');

        $this->assertEquals(
            '{"attributes":{"type":"Account"},"Name":"Test Object","OwnerId":"A10500010129302A10"}',
            $json
        );
    }

    public function testSobjectSerialziationArray()
    {
        $sobject = new SObject("Account");

        $sobject->Name = 'Test Object';
        $sobject->OwnerId = 'A10500010129302A10';

        $json = $this->serializer->serialize([$sobject], 'json');

        $this->assertEquals(
            '[{"attributes":{"type":"Account"},"Name":"Test Object","OwnerId":"A10500010129302A10"}]',
            $json
        );
    }

    public function testSobjectDeserialize()
    {
        /** @var SObject $sobject */
        $sobject = $this->serializer->deserialize(
            '{"attributes":{"type":"Account","url":"/test/url"},"Name":"Test Object","OwnerId":"A10500010129302A10"}',
            SObject::class,
            'json'
        );

        $this->assertEquals("Account", $sobject->getType());
        $this->assertEquals("/test/url", $sobject->getUrl());
        $this->assertEquals("Test Object", $sobject->Name);
        $this->assertEquals("A10500010129302A10", $sobject->OwnerId);
    }
}
