<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 1:34 PM
 */

namespace AE\ConnectBundle\Tests\Metadata;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Tests\Entity\Account;
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
        $connection = $this->connectionManager->getConnection();
        $metadataRegistry = $connection->getMetadataRegistry();

        $this->assertNotNull($metadataRegistry);

        $metadata = $metadataRegistry->getMetadata();
        $this->assertNotEmpty($metadata);

        $metadatum = $metadataRegistry->findMetadataByClass(Account::class);

        $this->assertNotNull($metadatum);

        $this->assertArraySubset(['sfid' => 'Id', 'name' => 'Name', 'extId' => 'hcid__c'], $metadatum->getPropertyMap());
        $this->assertArraySubset(['extId'], $metadatum->getIdentifiers());

        $describe = $metadatum->getDescribe();

        $this->assertNotNull($describe);
        $this->assertNotNull($describe->getName());
        $this->assertEquals($metadatum->getSObjectType(), $describe->getName());
        $this->assertNotEmpty($describe->getFields());
    }
}
