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
     * @var string|null
     */
    private $alias;

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
     * @var int
     */
    private $bulkApiMinCount;

    /**
     * @var boolean
     */
    private $active = false;

    /**
     * Connection constructor.
     *
     * @param string $name
     * @param ClientInterface $streamingClient
     * @param RestClient $restClient
     * @param BulkClient $bulkClient
     * @param MetadataRegistry $metadataRegistry
     * @param bool $isDefault
     * @param int $bulkApiMinCount
     */
    public function __construct(
        string $name,
        ClientInterface $streamingClient,
        RestClient $restClient,
        BulkClient $bulkClient,
        MetadataRegistry $metadataRegistry,
        bool $isDefault = false,
        int $bulkApiMinCount = 100000
    ) {
        $this->name             = $name;
        $this->streamingClient  = $streamingClient;
        $this->restClient       = $restClient;
        $this->bulkClient       = $bulkClient;
        $this->metadataRegistry = $metadataRegistry;
        $this->isDefault        = $isDefault;
        $this->bulkApiMinCount  = $bulkApiMinCount;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return null|string
     */
    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /**
     * @param null|string $alias
     *
     * @return Connection
     */
    public function setAlias(?string $alias): Connection
    {
        $this->alias = $alias;

        return $this;
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

    public function getBulkApiMinCount(): int
    {
        return $this->bulkApiMinCount;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     *
     * @return Connection
     */
    public function setActive(bool $active): Connection
    {
        $this->active = $active;

        return $this;
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

        $request = $builder->build();

        // Don't be firing off if ya ain't got no ammo
        if (count($request->getCompositeRequest()) > 0) {
            $response = $this->getRestClient()->getCompositeClient()->sendCompositeRequest($request);

            foreach ($metadataRegistry->getMetadata() as $i => $metadatum) {
                $result = $response->findResultByReferenceId("{$metadatum->getSObjectType()}_{$i}");
                if (null !== $result && 200 === $result->getHttpStatusCode()) {
                    $metadatum->setDescribe($result->getBody());
                    $cacheId = "{$this->name}__{$metadatum->getClassName()}";
                    $this->metadataRegistry->getCache()->save($cacheId, $metadatum);
                }
            }
        }
    }
}
