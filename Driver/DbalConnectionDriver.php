<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 9:30 AM
 */

namespace AE\ConnectBundle\Driver;

use AE\ConnectBundle\AuthProvider\NullProvider;
use AE\ConnectBundle\Connection\Connection;
use AE\ConnectBundle\Connection\Dbal\AuthCredentialsInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionProxy;
use AE\ConnectBundle\Connection\Dbal\RefreshTokenCredentialsInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Metadata\RecordTypeMetadata;
use AE\ConnectBundle\Salesforce\Inbound\Polling\PollingService;
use AE\ConnectBundle\Sdk\AuthProvider\MutableOAuthProvider;
use AE\ConnectBundle\Streaming\ChangeEvent;
use AE\ConnectBundle\Streaming\GenericEvent;
use AE\ConnectBundle\Streaming\PlatformEvent;
use AE\ConnectBundle\Streaming\Topic;
use AE\ConnectBundle\AuthProvider\OAuthProvider;
use AE\ConnectBundle\AuthProvider\SoapProvider;
use AE\ConnectBundle\Streaming\Client as StreamingClient;
use AE\SalesforceRestSdk\AuthProvider\AuthProviderInterface;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\SalesforceRestSdk\Bayeux\Extension\ReplayExtension;
use AE\SalesforceRestSdk\Bayeux\Extension\SfdcExtension;
use AE\SalesforceRestSdk\Bayeux\Transport\LongPollingTransport;
use AE\SalesforceRestSdk\Rest\Client as RestClient;
use AE\SalesforceRestSdk\Bulk\Client as BulkClient;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Log\LoggerAwareInterface;
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
     * @var array|ConnectionProxy[]
     */
    private $proxies = [];

    /**
     * DbalConnectionDriver constructor.
     *
     * @param ConnectionManagerInterface $connectionManager
     * @param RegistryInterface $registry
     * @param PollingService $pollingService
     * @param null|LoggerInterface $logger
     */
    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        PollingService $pollingService,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->pollingService    = $pollingService;

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
            $config        = $proxy->getConfig();
            $class         = $config['login']['entity'];
            $manager       = $this->registry->getManagerForClass($class);
            $proxyRegistry = $proxy->getMetadataRegistry();
            $metadataCache = $proxyRegistry->getCache();

            try {
                /** @var AuthCredentialsInterface[] $entities */
                $entities = $manager->getRepository($class)->findAll();

                foreach ($entities as $entity) {
                    if (!($entity instanceof AuthCredentialsInterface)) {
                        throw new \RuntimeException("The class $class must implement ".AuthCredentialsInterface::class);
                    }

                    try {
                        $authProvider    = $this->createLoginProvider($entity, $proxy->getCache(), $proxy->getLogger());
                        $restClient      = $this->createRestClient($authProvider);
                        $bulkClient      = $this->createBulkClient($authProvider);
                        $streamingClient = $this->createStreamingClient($config, $authProvider, $entity->getName());
                    } catch (\Exception $e) {
                        $entity->setActive(false);
                        $manager->flush();
                        $this->logger->critical($e->getMessage());
                        $authProvider    = new NullProvider();
                        $restClient      = $this->createRestClient($authProvider);
                        $bulkClient      = $this->createBulkClient($authProvider);
                        $streamingClient = $this->createStreamingClient($config, $authProvider, $entity->getName());
                    }

                    // Build a MetadataRegistry for the new connection based on the proxy registry
                    $metadataRegistry = new MetadataRegistry($metadataCache);

                    foreach ($proxyRegistry->getMetadata() as $proxyMetadata) {
                        $metadata = null;
                        $cacheId  = "{$entity->getName()}__{$proxyMetadata->getClassName()}";
                        // Check to see if there's a cached version of the metadata
                        if ($metadataCache->contains($cacheId)) {
                            $metadata = $metadataCache->fetch($cacheId);
                        } elseif ($entity->isActive()) {
                            $metadata = new Metadata($entity->getName());
                            $metadata->setClassName($proxyMetadata->getClassName());
                            $metadata->setSObjectType($proxyMetadata->getSObjectType());

                            foreach ($proxyMetadata->getFieldMetadata() as $proxyFieldMeta) {
                                if ($proxyFieldMeta instanceof RecordTypeMetadata) {
                                    $fieldMetadata = new RecordTypeMetadata(
                                        $metadata,
                                        $proxyFieldMeta->getName(),
                                        $proxyFieldMeta->getProperty()
                                    );
                                } else {
                                    $fieldMetadata = new FieldMetadata(
                                        $metadata,
                                        $proxyFieldMeta->getProperty(),
                                        $proxyFieldMeta->getField(),
                                        $proxyFieldMeta->isIdentifier()
                                    );
                                }
                                $fieldMetadata->setSetter($proxyFieldMeta->getSetter());
                                $fieldMetadata->setGetter($proxyFieldMeta->getGetter());
                                $metadata->addFieldMetadata($fieldMetadata);
                            }

                            if (null !== $proxyMetadata->getConnectionNameField()) {
                                $metadata->setConnectionNameField(
                                    new FieldMetadata(
                                        $metadata,
                                        $proxyMetadata->getConnectionNameField()->getProperty()
                                    )
                                );
                            }

                            $metadataCache->save($cacheId, $metadata);
                        }

                        if (isset($metadata)) {
                            $metadataRegistry->addMetadata($metadata);
                        }
                    }

                    $connection = new Connection(
                        $entity->getName(),
                        $streamingClient,
                        $restClient,
                        $bulkClient,
                        $metadataRegistry
                    );

                    $connection->setAlias($proxy->getName());
                    $connection->setActive($entity->isActive());
                    $connection->setAppName($config['app_name']);
                    $connection->setAppFilteringEnabled($config['config']['app_filtering']['enabled']);
                    $connection->setPermittedFilteredObjects($config['config']['app_filtering']['permitted_objects']);

                    try {
                        if ($connection->isActive()) {
                            $connection->hydrateMetadataDescribe();
                        }
                    } catch (\Exception $e) {
                        if (!$entity->isActive()) {
                            $entity->setActive(false);
                            $manager->flush();
                            $this->logger->critical($e->getMessage());
                        }
                    }

                    $this->connectionManager->registerConnection($connection);
                }
            } catch (TableNotFoundException $e) {
                $this->logger->error($e->getMessage());
                $this->logger->debug($e->getTraceAsString());
            }
        }
    }

    /**
     * @param AuthCredentialsInterface $entity
     * @param CacheProvider $cache
     * @param LoggerInterface|null $logger
     *
     * @return AuthProviderInterface
     */
    private function createLoginProvider(
        AuthCredentialsInterface $entity,
        CacheProvider $cache,
        ?LoggerInterface $logger = null
    ): AuthProviderInterface {
        if ($entity->getType() === AuthCredentialsInterface::SOAP) {
            return new SoapProvider($cache, $entity->getUsername(), $entity->getPassword(), $entity->getLoginUrl());
        }

        if ($entity->getType() === AuthCredentialsInterface::OAUTH) {
            if ($entity instanceof RefreshTokenCredentialsInterface) {
                $provider = new MutableOAuthProvider(
                    $cache,
                    $entity->getClientKey(),
                    $entity->getClientSecret(),
                    $entity->getLoginUrl(),
                    $entity->getUsername(),
                    null,
                    MutableOAuthProvider::GRANT_CODE,
                    $entity->getRedirectUri(),
                    ''
                );

                $token        = $entity->getToken();
                $refreshToken = $entity->getRefreshToken();

                if (null === $token) {
                    throw new \RuntimeException("Cannot authorize a grant code without a token.");
                }

                $provider->setToken($token);

                if (null !== $refreshToken) {
                    $provider->setRefreshToken($refreshToken);
                }

                if ($provider instanceof LoggerAwareInterface) {
                    $provider->setLogger($logger ?: new NullLogger());
                }

                return $provider;
            }

            $provider = new OAuthProvider(
                $entity->getClientKey(),
                $entity->getClientSecret(),
                $entity->getLoginUrl(),
                $entity->getUsername(),
                $entity->getPassword()
            );

            if ($provider instanceof LoggerAwareInterface) {
                $provider->setLogger($logger ?: new NullLogger());
            }

            return $provider;
        }

        throw new \LogicException("Logically, you should not have gotten here. Check your credential entity's type.");
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
        $this->loadChangeEvents(
            $client,
            $config['objects'],
            $connectionName,
            $config['config']['use_change_data_capture']
        );

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
     * @param bool $useCdc
     */
    private function loadChangeEvents(
        StreamingClient $client,
        array $config,
        string $connectionName,
        bool $useCdc = true
    ) {
        foreach ($config as $objectName) {
            if ($useCdc && (preg_match('/__(c|C)$/', $objectName) == true
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
                    ))
            ) {
                $client->addSubscriber(new ChangeEvent($objectName));
            } else {
                $this->pollingService->registerObject($objectName, $connectionName);
            }
        }
    }
}
