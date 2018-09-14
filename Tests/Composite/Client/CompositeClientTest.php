<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/14/18
 * Time: 10:25 AM
 */

namespace AE\ConnectBundle\Tests\Composite\Client;

use AE\ConnectBundle\Composite\Client\CompositeClient;
use AE\ConnectBundle\Composite\Model\CompositeRequest;
use AE\ConnectBundle\Composite\Model\SObject;
use AE\ConnectBundle\Manager\ConnectionManager;
use AE\ConnectBundle\Tests\KernelTestCase;

class CompositeClientTest extends KernelTestCase
{
    /**
     * @var CompositeClient
     */
    private $client;

    protected function setUp()
    {
        parent::setUp();
        /** @var ConnectionManager $manager */
        $manager      = $this->get(ConnectionManager::class);
        $this->client = $manager->getConnection()->getRestClient();
    }

    public function testCreate()
    {
        $account       = new SObject('Account');
        $account->Name = "Composite Test Account";

        $contact            = new SObject('Contact');
        $contact->FirstName = "Composite";
        $contact->LastName  = "Test Contact";

        $request = new CompositeRequest(
            [
                $account,
                $contact,
            ],
            true
        );

        $responses = $this->client->create($request);

        $this->assertEquals(2, count($responses));

        $this->assertTrue($responses[0]->isSuccess());
        $this->assertTrue($responses[1]->isSuccess());
        $this->assertNotNull($responses[0]->getId());
        $this->assertNotNull($responses[1]->getId());

        return [
            'account' => $responses[0]->getId(),
            'contact' => $responses[1]->getId(),
        ];
    }

    /**
     * @depends testCreate
     * @param array $ids
     *
     * @return array
     */
    public function testRead(array $ids)
    {
        $accounts = $this->client->read('Account', [$ids['account']], ['id', 'Name', 'CreatedDate']);

        $this->assertEquals(1, count($accounts));
        $account = $accounts[0];

        $this->assertEquals($ids['account'], $account->Id);
        $this->assertEquals('Composite Test Account', $account->Name);

        $contacts = $this->client->read(
            'Contact',
            [$ids['contact']],
            ['id', 'Name', 'FirstName', 'LastName', 'CreatedDate']
        );

        $this->assertEquals(1, count($contacts));
        $contact = $contacts[0];

        $this->assertEquals($ids['contact'], $contact->Id);
        $this->assertEquals('Composite Test Contact', $contact->Name);

        return $ids;
    }

    /**
     * @depends testRead
     * @param array $ids
     *
     * @return array
     */
    public function testUpdate(array $ids)
    {
        $account = new SObject('Account', ['id' => $ids['account'], 'Name' => 'Composite Test Update']);
        $contact = new SObject('Contact', ['id' => $ids['contact'], 'LastName' => 'Test Update']);

        $responses = $this->client->update(
            new CompositeRequest(
                [
                    $account,
                    $contact,
                ]
            )
        );

        $this->assertEquals(2, count($responses));
        $this->assertTrue($responses[0]->isSuccess());
        $this->assertTrue($responses[1]->isSuccess());
        $this->assertEquals($ids['account'], $responses[0]->getId());
        $this->assertEquals($ids['contact'], $responses[1]->getId());

        return $ids;
    }

    /**
     * @depends testUpdate
     * @param array $ids
     */
    public function testDelete(array $ids)
    {
        $responses = $this->client->delete(
            new CompositeRequest(
                [
                    new SObject('Account', ['id' => $ids['account']]),
                    new SObject('Contact', ['id' => $ids['contact']]),
                ]
            )
        );

        $this->assertEquals(2, count($responses));
        $this->assertTrue($responses[0]->isSuccess());
        $this->assertTrue($responses[1]->isSuccess());
        $this->assertEquals($ids['account'], $responses[0]->getId());
        $this->assertEquals($ids['contact'], $responses[1]->getId());
    }
}
