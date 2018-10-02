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
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;

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
     * @param MetadataRegistry $metadataRegistry
     * @param bool $isDefault
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function __construct(
        string $name,
        ClientInterface $streamingClient,
        RestClient $restClient,
        BulkClient $bulkClient,
        MetadataRegistry $metadataRegistry,
        bool $isDefault = false
    ) {
        $this->name             = $name;
        $this->streamingClient  = $streamingClient;
        $this->restClient       = $restClient;
        $this->bulkClient       = $bulkClient;
        $this->metadataRegistry = $metadataRegistry;
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

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function hydrateMetadataDescribe(): void
    {
        $builder          = new CompositeRequestBuilder();
        $metadataRegistry = $this->getMetadataRegistry();

        foreach ($metadataRegistry->getMetadata() as $i => $metadatum) {
            if (null === $metadatum->getDescribe()) {
                $builder->describe("{$metadatum->getSObjectType()}_{$i}", $metadatum->getSObjectType());
            }
        }

        $response = $this->getRestClient()->getCompositeClient()->sendCompositeRequest($builder->build());

        foreach ($metadataRegistry->getMetadata() as $i => $metadatum) {
            $result = $response->findResultByReferenceId("{$metadatum->getSObjectType()}_{$i}");
            if (null !== $result && 200 === $result->getHttpStatusCode()) {
                $metadatum->setDescribe($result->getBody());
            }
        }
    }
}
