<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/3/19
 * Time: 2:17 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Compiler;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\KernelTestCase;
use AE\SalesforceRestSdk\Model\SObject;
use Ramsey\Uuid\Uuid;

class FieldCompilerTest extends KernelTestCase
{
    /**
     * @var FieldCompiler
     */
    private $fieldCompiler;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp()
    {
        parent::setUp();
        $this->fieldCompiler = $this->get(FieldCompiler::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    public function testOutbound()
    {
        $connection = $this->connectionManager->getConnection();

        $extId = Uuid::uuid4();

        $account = new Account();
        $account->setExtId($extId);

        $metadata = $connection->getMetadataRegistry()->findMetadataForEntity($account);

        $ret = $this->fieldCompiler->compileOutbound(
            $extId->toString(),
            $account,
            $metadata->getMetadataForProperty('extId')
        );

        $this->assertEquals($extId, $ret);
    }

    public function testInbound()
    {
        $connection = $this->connectionManager->getConnection();

        $extId = Uuid::uuid4()->toString();

        $object = new SObject([
            'S3F__hcid__c' => $extId
        ]);

        $metadata = $connection->getMetadataRegistry()->findMetadataByClass(Account::class);

        $ret = $this->fieldCompiler->compileInbound(
            $extId,
            $object,
            $metadata->getMetadataForField('S3F__hcid__c')
        );

        $this->assertEquals(Uuid::fromString($extId), $ret);
    }
}
