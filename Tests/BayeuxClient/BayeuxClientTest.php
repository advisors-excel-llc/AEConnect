<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/10/18
 * Time: 10:27 AM
 */
namespace AE\ConnectBundle\Tests\BayeuxClient;

use AE\ConnectBundle\Bayeux\BayeuxClient;

class BayeuxClientTest extends \AE\ConnectBundle\Tests\KernelTestCase
{

    public function testConnect()
    {

    }

    public function testHandshake()
    {
        $client = static::$kernel->getContainer()->get(BayeuxClient::class);

        $handshake = $client->handshake();

        $this->assertTrue($handshake);
    }
}
