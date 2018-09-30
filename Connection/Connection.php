<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:06 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Streaming\ClientInterface;
use AE\SalesforceRestSdk\Rest\Client;

class Connection implements ConnectionInterface
{
    /**
     * @var ClientInterface
     */
    private $streamingClient;

    /**
     * @var Client
     */
    private $restClient;

    public function __construct(ClientInterface $streamingClient, Client $restClient)
    {
        $this->streamingClient = $streamingClient;
        $this->restClient      = $restClient;
    }

    public function getStreamingClient(): ClientInterface
    {
        return $this->streamingClient;
    }

    public function getRestClient()
    {
        return $this->restClient;
    }

}
