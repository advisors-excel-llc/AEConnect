<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 9:30 AM
 */

namespace AE\ConnectBundle\Driver;

use AE\ConnectBundle\Connection\Connection;
use AE\ConnectBundle\Connection\Dbal\AuthCredentialsInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionProxy;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Salesforce\Inbound\Polling\PollingService;
use AE\ConnectBundle\Streaming\ChangeEvent;
use AE\ConnectBundle\Streaming\GenericEvent;
use AE\ConnectBundle\Streaming\PlatformEvent;
use AE\ConnectBundle\Streaming\Topic;
use AE\SalesforceRestSdk\AuthProvider\AuthProviderInterface;
use AE\SalesforceRestSdk\AuthProvider\OAuthProvider;
use AE\SalesforceRestSdk\AuthProvider\SoapProvider;
use AE\ConnectBundle\Streaming\Client as StreamingClient;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\SalesforceRestSdk\Bayeux\Extension\ReplayExtension;
use AE\SalesforceRestSdk\Bayeux\Extension\SfdcExtension;
use AE\SalesforceRestSdk\Bayeux\Transport\LongPollingTransport;
use AE\SalesforceRestSdk\Rest\Client as RestClient;
use AE\SalesforceRestSdk\Bulk\Client as BulkClient;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * Class DbalConnectionDriver
 *
 * @package AE\ConnectBundle\Driver
 */
class DbalConnectionDriver
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var PollingService
     */
    private $pollingService;

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var array|ConnectionProxy[]
     */
    private $proxies = [];

    /**
     * DbalConnectionDriver constructor.
     *
     * @param ConnectionManagerInterface $connectionManager
     * @param RegistryInterface $registry
     * @param PollingService $pollingService
     * @param CacheProvider $cache
     * @param null|LoggerInterface $logger
     */
    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        PollingService $pollingService,
        CacheProvider $cache,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->pollingService    = $pollingService;
        $this->cache             = $cache;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param ConnectionProxy $proxy
     */
    public function addConnectionProxy(ConnectionProxy $proxy)
    {
        $this->proxies[] = $proxy;
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException|\Exception
     */
    public function loadConnections()
    {
        foreach ($this->proxies as $proxy) {
            $config  = $proxy->getConfig();
            $class   = $config['entity'];
            $manager = $this->registry->getManagerForClass($class);
            /** @var AuthCredentialsInterface[] $entities */
            $entities = $manager->getRepository($class)->findAll();

            foreach ($entities as $entity) {
                if (!($entity instanceof AuthCredentialsInterface)) {
                    throw new \RuntimeException("The class $class must implement ".AuthCredentialsInterface::class);
                }

                // Check the cache to see if the connection has been created
                if ($this->cache->contains($entity->getName())) {
                    $connection = $this->cache->fetch($entity->getName());
                    $this->connectionManager->registerConnection($connection);

                    continue;
                }

                $authProvider    = $this->createLoginProvider($entity);
                $restClient      = $this->createRestClient($authProvider);
                $bulkClient      = $this->createBulkClient($authProvider);
                $streamingClient = $this->createStreamingClient($config, $authProvider, $entity->getName());

                // Build a MetadataRegistry for the new connection based on the proxy registry
                $proxyRegistry    = $proxy->getMetadataRegistry();
                $metadataCache    = $proxyRegistry->getCache();
                $metadataRegistry = new MetadataRegistry($metadataCache);

                foreach ($proxyRegistry->getMetadata() as $proxyMetadata) {
                    $cacheId = "{$entity->getName()}__{$proxyMetadata->getClassName()}";
                    // Check to see if there's a cached version of the metadata
                    if ($metadataCache->contains($cacheId)) {
                        $metadata = $metadataCache->fetch($cacheId);
                    } else {
                        $metadata = new Metadata($entity->getName());
                        $metadata->setClassName($proxyMetadata->getClassName());
                        $metadata->setSObjectType($proxyMetadata->getSObjectType());
                        $metadata->setFieldMetadata(new ArrayCollection($proxyMetadata->getFieldMetadata()->toArray()));
                        $metadataCache->save($cacheId, $metadata);
                    }

                    $metadataRegistry->addMetadata($metadata);
                }

                $connection = new Connection(
                    $entity->getName(),
                    $streamingClient,
                    $restClient,
                    $bulkClient,
                    $metadataRegistry
                );

                $connection->setAlias($proxy->getName());
                $connection->hydrateMetadataDescribe();

                $this->connectionManager->registerConnection($connection);

                $this->cache->save($entity->getName(), $connection);
            }
        }
    }

    /**
     * @param AuthCredentialsInterface $entity
     *
     * @return AuthProviderInterface
     */
    private function createLoginProvider(AuthCredentialsInterface $entity): AuthProviderInterface
    {
        if ($entity->getType() === AuthCredentialsInterface::SOAP) {
            return new SoapProvider($entity->getUsername(), $entity->getPassword(), $entity->getLoginUrl());
        }

        return new OAuthProvider(
            $entity->getClientKey(),
            $entity->getClientSecret(),
            $entity->getUsername(),
            $entity->getPassword(),
            $entity->getLoginUrl()
        );
    }

    /**
     * @param array $config
     * @param AuthProviderInterface $authProvider
     * @param string $connectionName
     *
     * @return StreamingClient
     * @throws \Exception
     */
    private function createStreamingClient(
        array $config,
        AuthProviderInterface $authProvider,
        string $connectionName
    ): StreamingClient {
        $bayeuxClient = new BayeuxClient(new LongPollingTransport(), $authProvider);

        $bayeuxClient->addExtension(new ReplayExtension($config['config']['replay_start_id']))
                     ->addExtension(new SfdcExtension())
        ;

        $client = new StreamingClient($bayeuxClient);

        $this->loadTopics($client, $config['topics']);
        $this->loadPlatformEvents($client, $config['platform_events']);
        $this->loadGenericEvents($client, $config['generic_events']);
        $this->loadChangeEvents($client, $config['objects'], $connectionName);

        return $client;
    }

    /**
     * @param AuthProviderInterface $authProvider
     *
     * @return RestClient
     */
    private function createRestClient(AuthProviderInterface $authProvider): RestClient
    {
        return new RestClient($authProvider);
    }

    /**
     * @param AuthProviderInterface $authProvider
     *
     * @return BulkClient
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     */
    private function createBulkClient(AuthProviderInterface $authProvider): BulkClient
    {
        return new BulkClient($authProvider);
    }

    /**
     * @param StreamingClient $client
     * @param array $config
     */
    private function loadTopics(StreamingClient $client, array $config)
    {
        foreach ($config as $topicConf) {
            $topic = new Topic();
            $topic->setName($topicConf['name']);
            $topic->setFilters($topicConf['filter']);
            $topic->setType($topicConf['type']);

            $client->addSubscriber($topic);
        }
    }

    /**
     * @param StreamingClient $client
     * @param array $config
     */
    private function loadPlatformEvents(StreamingClient $client, array $config)
    {
        foreach ($config as $event) {
            $client->addSubscriber(new PlatformEvent($event));
        }
    }

    /**
     * @param StreamingClient $client
     * @param array $config
     */
    private function loadGenericEvents(StreamingClient $client, array $config)
    {
        foreach ($config as $event) {
            $client->addSubscriber(new GenericEvent($event));
        }
    }

    /**
     * @param StreamingClient $client
     * @param array $config
     * @param string $connectionName
     */
    private function loadChangeEvents(StreamingClient $client, array $config, string $connectionName)
    {
        foreach ($config as $objectName) {
            if (preg_match('/__(c|C)$/', $objectName) == true
                || in_array(
                    $objectName,
                    [
                        'Account',
                        'Asset',
                        'Campaign',
                        'Case',
                        'Contact',
                        'ContractLineItem',
                        'Entitlement',
                        'Lead',
                        'LiveChatTranscript',
                        'Opportunity',
                        'Order',
                        'OrderItem',
                        'Product2',
                        'Quote',
                        'QuoteLineItem',
                        'ServiceContract',
                        'User',
                    ]
                )
            ) {
                $client->addSubscriber(new ChangeEvent($objectName));
            } else {
                $this->pollingService->registerObject($objectName, $connectionName);
            }
        }
    }
}
