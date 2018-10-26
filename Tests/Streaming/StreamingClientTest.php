<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 2:29 PM
 */

namespace AE\ConnectBundle\Tests\Streaming;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Streaming\Client;
use AE\ConnectBundle\Tests\DatabaseTestCase;
use AE\ConnectBundle\Tests\Entity\Account;
use AE\ConnectBundle\Tests\Entity\TestObject;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Consumer;
use AE\SalesforceRestSdk\Bayeux\Message;
use AE\SalesforceRestSdk\Model\SObject;

class StreamingClientTest extends DatabaseTestCase
{
    /** @var Client */
    private $streamingClient;

    /** @var \AE\SalesforceRestSdk\Rest\SObject\Client */
    private $sObjectClient;

    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        parent::setUp();

        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager     = $this->get(ConnectionManagerInterface::class);
        $connection            = $connectionManager->getConnection();
        $this->sObjectClient   = $connection->getRestClient()->getSObjectClient();
        $this->streamingClient = $connection->getStreamingClient();
    }

    protected function loadSchemas(): array
    {
        return [
            Account::class,
            TestObject::class,
        ];
    }

    public function testNewAccount()
    {
        $subscriber = $this->streamingClient->getSubscriber('/data/AccountChangeEvent');
        $accountId  = null;

        $this->streamingClient->getClient()
                              ->getChannel(ChannelInterface::META_HANDSHAKE)
                              ->subscribe(
                                  ($consumer = Consumer::create(
                                      function (ChannelInterface $channel, Message $message) use (&$consumer) {
                                          $this->sObjectClient->persist(
                                              'Account',
                                              new SObject(
                                                  [
                                                      'Name' => 'Test Streaming Account',
                                                  ]
                                              )
                                          );
                                          $channel->unsubscribe($consumer);
                                      },
                                      1
                                  ))
                              )
        ;

        $subscriber->addConsumer(
            Consumer::create(
                function (ChannelInterface $channel, Message $message) use (&$accountId) {
                    $data    = $message->getData();
                    $payload = $data->getPayload();
                    $this->assertNotNull($payload);
                    $this->assertArrayHasKey('ChangeEventHeader', $payload);
                    $accountId = $payload['ChangeEventHeader']['recordIds'][0];
                    $this->streamingClient->stop();
                },
                2
            )
        );

        $this->streamingClient->start();

        $this->assertNotNull($accountId);
        $account = $this->doctrine->getManagerForClass(Account::class)
                                  ->getRepository(Account::class)
                                  ->findOneBy(['sfid' => $accountId])
        ;
        $this->assertNotNull($account);
    }

    public function testNewAccountViaTopic()
    {
        $subscriber = $this->streamingClient->getSubscriber('/topic/TestObjects');
        $objectId   = null;

        $this->streamingClient->getClient()
                              ->getChannel(ChannelInterface::META_HANDSHAKE)
                              ->subscribe(
                                  ($consumer = Consumer::create(
                                      function (ChannelInterface $channel, Message $message) use (&$consumer) {
                                          $response = $this->sObjectClient->persist(
                                              'S3F__Test_Object__c',
                                              new SObject(
                                                  [
                                                      'Name' => 'Test Streaming Object',
                                                  ]
                                              )
                                          );
                                          $channel->unsubscribe($consumer);
                                          $this->assertTrue($response);
                                      },
                                      6
                                  ))
                              )
        ;

        $subscriber->addConsumer(
            Consumer::create(
                function (ChannelInterface $channel, Message $message) use (&$objectId) {
                    $data   = $message->getData();
                    $object = $data->getSobject();
                    $this->assertNotNull($object);
                    $this->assertEquals('S3F__Test_Object__c', $object->Type);
                    $objectId = $object->Id;
                    $this->streamingClient->stop();
                },
                2
            )
        );

        $this->streamingClient->start();

        $this->assertNotNull($objectId);
        $account = $this->doctrine->getManagerForClass(TestObject::class)
                                  ->getRepository(TestObject::class)
                                  ->findOneBy(['sfid' => $objectId])
        ;
        $this->assertNotNull($account);
    }
}
