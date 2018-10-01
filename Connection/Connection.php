<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:06 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Streaming\ClientInterface;
use AE\SalesforceRestSdk\Rest\Client as RestClient;
use AE\SalesforceRestSdk\Bulk\Client as BulkClient;

/**
 * Class Connection
 *
 * @package AE\ConnectBundle\Connection
 */
class Connection implements ConnectionInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $isDefault = false;

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

    /**
     * @var MetadataRegistry
     */
    private $metadataRegistry;

    /**
     * Connection constructor.
     *
     * @param string $name
     * @param ClientInterface $streamingClient
     * @param RestClient $restClient
     * @param BulkClient $bulkClient
     * @param bool $isDefault
     */
    public function __construct(
        string $name,
        ClientInterface $streamingClient,
        RestClient $restClient,
        BulkClient $bulkClient,
        bool $isDefault = false
    ) {
        $this->name             = $name;
        $this->streamingClient  = $streamingClient;
        $this->restClient       = $restClient;
        $this->bulkClient       = $bulkClient;
        $this->metadataRegistry = new MetadataRegistry();
        $this->isDefault        = $isDefault;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return ClientInterface
     */
    public function getStreamingClient(): ClientInterface
    {
        return $this->streamingClient;
    }

    /**
     * @return RestClient
     */
    public function getRestClient(): RestClient
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

    /**
     * @return MetadataRegistry
     */
    public function getMetadataRegistry(): MetadataRegistry
    {
        return $this->metadataRegistry;
    }

    /**
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
