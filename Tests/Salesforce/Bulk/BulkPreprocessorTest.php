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
use AE\SalesforceRestSdk\Model\SObject;

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

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();
        $this->preprocessor      = $this->get(BulkPreprocessor::class);
        $this->connectionManager = $this->get(ConnectionManagerInterface::class);
    }

    public function testPreprocess()
    {
        $account = new Account();

        $account->setName('Testo Accounto Numero Uno');
        $account->setSfid('01A000000g8392K');
        $account->setConnection('default');

        $this->doctrine->getManager()->persist($account);
        $this->doctrine->getManager()->flush();

        $sObject    = new SObject(
            [
                'Name'             => 'Some Dumb Name',
                'S3F__hcid__c'     => $account->getExtId(),
                'Id'               => $account->getId(),
                '__SOBJECT_TYPE__' => 'Account',
            ]
        );
        $connection = $this->connectionManager->getConnection();

        $sObject = $this->preprocessor->preProcess($sObject, $connection);

        $this->assertArraySubset(
            [
                'S3F__hcid__c'     => $account->getExtId(),
                'Id'               => $account->getId(),
                '__SOBJECT_TYPE__' => 'Account',
            ],
            $sObject->getFields()
        );
        $this->assertNull($sObject->Name);

        $this->doctrine->getManager()->remove($account);
        $this->doctrine->getManager()->flush();
    }
}
