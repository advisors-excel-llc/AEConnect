<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/26/19
 * Time: 10:53 AM
 */

namespace AE\ConnectBundle\Tests\Salesforce\Bulk;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Bulk\BulkPreprocessor;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Salesforce\SfidGenerator;
use AE\SalesforceRestSdk\Model\SObject;
use DMS\PHPUnitExtensions\ArraySubset\Assert;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

class BulkPreprocessorTest extends DatabaseTestCase
{
    /**
     * @var BulkPreprocessor
     */
    private $preprocessor;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preprocessor      = $this->get(BulkPreprocessor::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    protected function tearDown(): void
    {
        /** @var Connection $conn */
        $conn = $this->doctrine->getConnection();
        $conn->exec('DELETE FROM account;');
        parent::tearDown();
    }

    /**
     * @throws \Exception
     */
    public function testPreprocess()
    {
        $account = new Account();

        $account->setName('Testo Accounto Numero Uno');
        $account->setSfid(SfidGenerator::generate());
        $account->setExtId(Uuid::uuid4());
        $account->setConnection('default');

        $this->doctrine->getManager()->persist($account);
        $this->doctrine->getManager()->flush();

        $sObject    = new SObject(
            [
                'Name'             => 'Some Dumb Name',
                'S3F__hcid__c'     => $account->getExtId()->toString(),
                'Id'               => $account->getId(),
                '__SOBJECT_TYPE__' => 'Account',
            ]
        );
        $connection = $this->connectionManager->getConnection();
        $sObject    = $this->preprocessor->preProcess($sObject, $connection, false, true);

        Assert::assertArraySubset(
            [
                'S3F__hcid__c'     => $account->getExtId(),
                'Id'               => $account->getId(),
                '__SOBJECT_TYPE__' => 'Account',
            ],
            $sObject->getFields()
        );
        $this->assertNull($sObject->Name);
    }

    public function testPreprocessNoInsert()
    {
        $sObject    = new SObject(
            [
                'Name'             => 'Some Dumb Name For Insert',
                'S3F__hcid__c'     => Uuid::uuid4()->toString(),
                'Id'               => SfidGenerator::generate(),
                '__SOBJECT_TYPE__' => 'Account',
            ]
        );
        $connection = $this->connectionManager->getConnection();
        $sObject    = $this->preprocessor->preProcess($sObject, $connection);

        $this->assertNull($sObject);
    }
}
