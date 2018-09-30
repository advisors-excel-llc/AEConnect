<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:06 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Streaming\ClientInterface;
use AE\SalesforceRestSdk\Rest\Client as RestClient;
use AE\SalesforceRestSdk\Bulk\Client as BulkClient;

class Connection implements ConnectionInterface
{
    /**
     * @var ClientInterface
     */
    private $streamingClient;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var BulkClient
     */
    private $bulkClient;

    public function __construct(
        ClientInterface $streamingClient,
        RestClient $restClient,
        BulkClient $bulkClient
    ) {
        $this->streamingClient = $streamingClient;
        $this->restClient      = $restClient;
        $this->bulkClient      = $bulkClient;
    }

    public function getStreamingClient(): ClientInterface
    {
        return $this->streamingClient;
    }

    public function getRestClient()
    {
        return $this->restClient;
    }

    /**
     * @return BulkClient
     */
    public function getBulkClient(): BulkClient
    {
        return $this->bulkClient;
    }

    /**
     * @param BulkClient $bulkClient
     *
     * @return Connection
     */
    public function setBulkClient(BulkClient $bulkClient): Connection
    {
        $this->bulkClient = $bulkClient;

        return $this;
    }
}
