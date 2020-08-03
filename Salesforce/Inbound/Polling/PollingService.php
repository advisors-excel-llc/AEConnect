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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PollingService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CACHE_ID            = 'ae_connect_poll_last_updated';
    public const MAX_TYPES_PER_CHUNK = 5;

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
        SalesforceConnector $connector,
        ?LoggerInterface $logger = null
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

        $this->setLogger($logger ?: new NullLogger());
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
            $this->logger->debug('#PS01 No objects to poll for connection, "{conn}"', ['conn' => $connection->getName()]);

            return;
        }

        $chunks = array_chunk($objects, self::MAX_TYPES_PER_CHUNK);

        foreach ($chunks as $chunk) {
            $builder = new CompositeRequestBuilder();
            $client  = $connection->getRestClient()->getCompositeClient();
            $end     = (new \DateTime())->add(\DateInterval::createFromDateString('1 Minute'));

            foreach ($chunk as $object) {
                $builder->getUpdated('updated_'.$object, $object, $this->lastUpdated, $end);
                $builder->getDeleted('deleted_'.$object, $object, $this->lastUpdated, $end);
            }

            $this->logger->debug(
                '#PS02 Polling for changes to {obj} between {start} and {end}',
                [
                    'obj'   => $object,
                    'start' => $this->lastUpdated,
                    'end'   => $end,
                ]
            );

            $request  = $builder->build();
            $response = $client->sendCompositeRequest($request);
            $builder  = new CompositeRequestBuilder();
            $requests = [];
            $updates  = [];
            $removals = [];

            foreach ($response->getCompositeResponse() as $result) {
                if ($result->getHttpStatusCode() === 200) {
                    $parts  = explode('_', $result->getReferenceId());
                    $action = array_shift($parts);
                    $type   = implode('_', $parts);

                    $body = $result->getBody();
                    if ($body instanceof UpdatedResponse) {
                        $fields = [];
                        foreach ($connection->getMetadataRegistry()->findMetadataBySObjectType($type) as $metadata) {
                            $fields = array_merge($fields, array_values($metadata->getPropertyMap()));
                        }
                        $ids = array_chunk($body->getIds(), 2000);
                        foreach ($ids as $index => $chunk) {
                            if ($builder->countRequests() === self::MAX_TYPES_PER_CHUNK) {
                                $requests[] = $builder->build();

                                $builder = new CompositeRequestBuilder();
                            }

                            $referenceId = $result->getReferenceId().'_'.$index;
                            $builder->getSObjectCollection(
                                $referenceId,
                                $type,
                                $chunk,
                                $fields
                            );
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

            if ($builder->countRequests() > 0) {
                $requests[] = $builder->build();
            }

            foreach ($requests as $subRequest) {
                try {
                    $response = $client->sendCompositeRequest($subRequest);
                    foreach ($response->getCompositeResponse() as $result) {
                        if ($result->getHttpStatusCode() === 200) {
                            /** @var CompositeSObject[] $body */
                            $body    = $result->getBody();
                            $updates = array_merge($updates, $body);
                        } else {
                            $this->logger->error(
                                "#PS03 Received status code {code}: {msg}",
                                [
                                    'code' => $result->getHttpStatusCode(),
                                    'msg'  => json_encode($result->getBody()),
                                ]
                            );
                        }
                    }
                } catch (\RuntimeException $e) {
                    // A runtime exception is thrown if there are no requests to build.
                    $this->logger->critical('#PS04 Runtime Exception in Poll. '.$e->getMessage());
                }
            }

            if (empty($updates) && empty($removals)) {
                $this->logger->debug('#PS05 No objects to update or delete in Poll.');

                return;
            }

            /**
             * @var CompositeSObject $update
             */
            foreach ($updates as $update) {
                $update->__SOBJECT_TYPE__ = $update->getType();
                try {
                    $this->connector->receive($update, SalesforceConsumerInterface::UPDATED, $connectionName);
                } catch (\Exception $e) {
                    $this->logger->critical('#PS06 Exception trying Update Receive in Poll. '.$e->getMessage());
                }
            }

            foreach ($removals as $removal) {
                $removal->__SOBJECT_TYPE__ = $removal->getType();
                try {
                    $this->connector->receive($removal, SalesforceConsumerInterface::DELETED, $connectionName);
                } catch (\Exception $e) {
                    $this->logger->critical('#PS07 Exception trying Removal Receive in Poll. '.$e->getMessage());
                }
            }
        }

        $this->lastUpdated = new \DateTime();
        $this->cache->save(self::CACHE_ID, $this->lastUpdated->format(\DATE_ISO8601));
        $this->logger->debug(
            '#PS08 Polling completed at {time} for {conn}',
            [
                'time' => $this->lastUpdated->format(\DATE_ISO8601),
                'conn' => $connection->getName(),
            ]
        );
    }
}
