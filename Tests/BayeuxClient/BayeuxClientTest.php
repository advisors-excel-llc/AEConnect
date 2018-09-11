<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/10/18
 * Time: 10:27 AM
 */

namespace AE\ConnectBundle\Tests\BayeuxClient;

use AE\ConnectBundle\Bayeux\BayeuxClient;
use AE\ConnectBundle\Bayeux\ChannelInterface;
use AE\ConnectBundle\Bayeux\Consumer;
use AE\ConnectBundle\Bayeux\Message;
use Asynchronicity\PHPUnit\Asynchronicity;
use GuzzleHttp\Client;

class BayeuxClientTest extends \AE\ConnectBundle\Tests\KernelTestCase
{
    use Asynchronicity;
    /**
     * @var BayeuxClient
     */
    private $client;

    protected function setUp()
    {
        parent::setUp();
        $this->client = static::$kernel->getContainer()->get(BayeuxClient::class);
    }

    public function testStream()
    {
        $rand = rand(100, 1000);
        $name = 'Test Account '.$rand;
        $connectCount = 0;

        $consumer = Consumer::create(
            function (ChannelInterface $channel, Message $message) use ($name, &$consumer, &$connectCount) {
                $this->assertTrue($message->isSuccessful());
                $this->assertFalse($this->client->isDisconnected());
                ++$connectCount;

                // Need to wait until the initial connection is established before initiating
                if ($connectCount == 2) {
                    $client   = new Client(['base_uri' => getenv('SF_URL')]);
                    $response = $client->post(
                        'services/data/v43.0/sobjects/Account',
                        [
                            'headers' => [
                                'Content-Type'  => 'application/json',
                                'Accept'        => 'application/json',
                                'Authorization' => $this->client->getAuthProvider()->authorize(),
                            ],
                            'json'    => ['Name' => $name],
                        ]
                    );

                    $this->assertEquals(201, $response->getStatusCode());
                    $channel->unsubscribe($consumer);
                }
            }
        );

        $this->client->getChannel(ChannelInterface::META_CONNECT)->subscribe($consumer);

        $channel = $this->client->getChannel('/topic/Accounts');
        $channel->subscribe(
            Consumer::create(
                function (ChannelInterface $channel, Message $message) use ($name) {
                    $this->assertEquals("/topic/Accounts", $channel->getChannelId());
                    $data = $message->getData();
                    $this->assertNotNull($data);
                    $sobject = $data->getSobject();
                    $this->assertNotNull($sobject);
                    $this->assertNotEmpty($sobject);
                    $this->assertArrayHasKey('Name', $sobject);
                    $this->assertEquals($name, $sobject['Name']);

                    if (!$this->client->isDisconnected()) {
                        $this->client->disconnect();
                    }
                }
            )
        );

        $this->client->start();
    }
}
