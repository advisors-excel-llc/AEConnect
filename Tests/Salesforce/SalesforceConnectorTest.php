<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 3:53 PM
 */

namespace AE\ConnectBundle\Tests\Salesforce;

use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use Enqueue\Client\ProducerInterface;

class SalesforceConnectorTest extends DatabaseTestCase
{
    /**
     * @var SalesforceConnector
     */
    private $connector;

    protected function loadSchemas(): array
    {
        return [
            Account::class
        ];
    }

    protected function setUp()
    {
        parent::setUp();
        $this->connector = new SalesforceConnector(
            'test_run',
            $this->doctrine,
            $this->get('ae_connect.connection_manager'),
            new Transformer(),
            $this->get('validation'),
            $this->get(ProducerInterface::class)
        );
    }

    public function testOutgoing()
    {
        $manager = $this->doctrine->getManagerForClass(Account::class);
        $account = new Account();

        $account->setSfid("1");
        $account->setName("Test");

        $manager->persist($account);
        $this->connector->send($account);

        static::$container->get('');
    }
}
