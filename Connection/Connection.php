<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:06 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Streaming\ClientInterface;

class Connection implements ConnectionInterface
{
    /**
     * @var ClientInterface
     */
    private $streamingClient;

    public function __construct(ClientInterface $streamingClient)
    {
        $this->streamingClient = $streamingClient;
    }

    public function getStreamingClient(): ClientInterface
    {
        return $this->streamingClient;
    }

    public function getRestClient()
    {
        // TODO: Implement getRestClient() method.
    }

}
