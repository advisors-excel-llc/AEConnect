<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 5:57 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Polling;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use AE\SalesforceRestSdk\Model\Rest\DeletedRecord;
use AE\SalesforceRestSdk\Model\Rest\DeletedResponse;
use AE\SalesforceRestSdk\Model\Rest\UpdatedResponse;
use AE\SalesforceRestSdk\Model\SObject;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use Doctrine\Common\Cache\CacheProvider;

class PollingService
{
    public const CACHE_ID = 'ae_connect_poll_last_updated';
    /**
     * @var array
     */
    private $objects = [];

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var \DateTime
     */
    private $lastUpdated;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        CacheProvider $cache,
        SalesforceConnector $connector
    ) {
        $this->connectionManager = $connectionManager;
        $this->cache             = $cache;
        $this->connector         = $connector;

        if ($this->cache->contains(self::CACHE_ID)) {
            $this->lastUpdated = new \DateTime($this->cache->fetch(self::CACHE_ID));
        } else {
            $this->lastUpdated = new \DateTime();
            $this->cache->save(self::CACHE_ID, $this->lastUpdated->format(\DATE_ISO8601));
        }
    }

    /**
     * @param string $objectName
     * @param string $connectionName
     *
     * @return $this
     */
    public function registerObject(string $objectName, string $connectionName = 'default')
    {
        if (!array_key_exists($connectionName, $this->objects)) {
            $this->objects[$connectionName] = [];
        }

        $this->objects[$connectionName][] = $objectName;

        return $this;
    }

    /**
     * @param string $connectionName
     *
     * @return array
     */
    public function getObjects(string $connectionName = 'default'): array
    {
        return array_key_exists($connectionName, $this->objects) ? $this->objects[$connectionName] : [];
    }

    public function poll(string $connectionName = 'default'): void
    {
        $connection = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            throw new \RuntimeException("Connection '$connectionName' is not configured.");
        }

        $objects = $this->getObjects($connectionName);

        if (empty($objects)) {
            return;
        }

        $chunks = array_chunk($objects, 12);

        foreach ($chunks as $chunk) {
            $builder = new CompositeRequestBuilder();
            $client  = $connection->getRestClient()->getCompositeClient();
            $end     = (new \DateTime())->add(\DateInterval::createFromDateString('1 Minute'));

            foreach ($chunk as $object) {
                $builder->getUpdated('updated_'.$object, $object, $this->lastUpdated, $end);
                $builder->getDeleted('deleted_'.$object, $object, $this->lastUpdated, $end);
            }

            $request  = $builder->build();
            $response = $client->sendCompositeRequest($request);
            $builder  = new CompositeRequestBuilder();
            $updates  = [];
            $removals = [];

            foreach ($response->getCompositeResponse() as $result) {
                if ($result->getHttpStatusCode() === 200) {
                    $parts = explode('_', $result->getReferenceId());
                    $action = array_shift($parts);
                    $type = implode('_', $parts);

                    $body = $result->getBody();
                    if ($body instanceof UpdatedResponse) {
                        $fields = [];
                        foreach ($connection->getMetadataRegistry()->findMetadataBySObjectType($type) as $metadata) {
                            $fields = array_merge($fields, array_values($metadata->getPropertyMap()));
                        }
                        if ($body->getIds()) {
                            $builder->getSObjectCollection($result->getReferenceId(), $type, $body->getIds(), $fields);
                        }
                    } elseif ($body instanceof DeletedResponse) {
                        /** @var DeletedRecord[] $records */
                        $records = $body->getDeletedRecords();
                        foreach ($records as $record) {
                            $removals[] = new SObject(['Id' => $record->getId(), 'Type' => $type]);
                        }
                    }
                }
            }

            try {
                $response = $client->sendCompositeRequest($builder->build());
                foreach ($response->getCompositeResponse() as $result) {
                    if ($result->getHttpStatusCode() === 200) {
                        /** @var CompositeSObject[] $body */
                        $body    = $result->getBody();
                        $updates = array_merge($updates, $body);
                    }
                }
            } catch (\RuntimeException $e) {
                // A runtime exception is thrown if there are no requests to build.
            }

            if (empty($updates) && empty($removals)) {
                return;
            }

            /**
             * @var CompositeSObject $update
             */
            foreach ($updates as $update) {
                $update->__SOBJECT_TYPE__ = $update->getType();
                $this->connector->receive($update, SalesforceConsumerInterface::UPDATED, $connectionName);
            }

            foreach ($removals as $removal) {
                $removal->__SOBJECT_TYPE__ = $removal->getType();
                $this->connector->receive($removal, SalesforceConsumerInterface::DELETED, $connectionName);
            }
        }

        $this->lastUpdated = new \DateTime();
        $this->cache->save(self::CACHE_ID, $this->lastUpdated->format(\DATE_ISO8601));
    }
}
