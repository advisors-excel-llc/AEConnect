<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle\DependencyInjection;

use AE\ConnectBundle\Connection\Connection;
use AE\ConnectBundle\Connection\Dbal\ConnectionProxy;
use AE\ConnectBundle\Driver\AnnotationDriver;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Metadata\MetadataRegistryFactory;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\ConnectBundle\Streaming\ChangeEvent;
use AE\ConnectBundle\Streaming\GenericEvent;
use AE\ConnectBundle\Streaming\PlatformEvent;
use AE\ConnectBundle\AuthProvider\OAuthProvider;
use AE\ConnectBundle\AuthProvider\SoapProvider;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\ConnectBundle\Streaming\Client;
use AE\ConnectBundle\Streaming\Extension\ReplayExtension;
use AE\ConnectBundle\Streaming\Topic;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class AEConnectExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param array $configs
     * @param ContainerBuilder $container
     *
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('transformers.yml');
        $loader->load('services.yml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->createAnnotationDriver($container, $config);
        $this->processConnections($container, $config);
    }

    /**
     * @inheritDoc
     */
    public function prepend(ContainerBuilder $container)
    {
        $this->processDoctrineCache($container);
        $this->processEnqueueClient($container);
    }

    /**
     * @param ContainerBuilder $container
     */
    protected function processDoctrineCache(ContainerBuilder $container): void
    {
        foreach ($container->getExtensionConfig('doctrine_cache') as $config) {
            $providers      = array_key_exists('providers', $config) ? $config['providers'] : [];
            $providerConfig = [];

            if (!array_key_exists('ae_connect_metadata', $providers)) {
                $providerConfig['ae_connect_metadata'] = [
                    'type' => 'file_system',
                ];
            }

            if (!array_key_exists('ae_connect_outbound_queue', $providers)) {
                $providerConfig['ae_connect_outbound_queue'] = [
                    'type' => 'file_system',
                ];
            }

            if (!array_key_exists('ae_connect_polling', $providers)) {
                $providerConfig['ae_connect_polling'] = [
                    'type' => 'file_system',
                ];
            }

            if (!array_key_exists('ae_connect_auth', $providers)) {
                $providerConfig['ae_connect_auth'] = [
                    'type' => 'file_system',
                ];
            }

            if (!array_key_exists('ae_connect_replay', $providers)) {
                $providerConfig['ae_connect_replay'] = [
                    'type' => 'file_system',
                ];
            }

            $container->prependExtensionConfig(
                'doctrine_cache',
                [
                    'providers' => $providerConfig,
                ]
            );
        }
    }

    protected function processEnqueueClient(ContainerBuilder $container)
    {
        $clientConfig = [];

        foreach ($container->getExtensionConfig('enqueue') as $config) {
            if (array_key_exists('ae_connect', $config)) {
                $clientConfig = array_merge($clientConfig, $config['ae_connect']);
                continue;
            }

            if (!array_key_exists('default', $config)) {
                continue;
            }

            $clientConfig = array_merge($clientConfig, $config['default']);
        }

        if (empty($clientConfig)) {
            throw new InvalidConfigurationException('No enqueue configuration defined for "ae_connect"');
        }

        $clientConfig['client']['prefix']   = 'ae_connect';
        $clientConfig['client']['app_name'] = 'ae_connect';

        $container->prependExtensionConfig('enqueue', ['ae_connect' => $clientConfig]);
    }

    private function createAnnotationDriver(ContainerBuilder $container, array $config)
    {
        $definition = new Definition(AnnotationDriver::class, [new Reference("annotation_reader"), $config['paths']]);

        $container->setDefinition("ae_connect.annotation_driver", $definition);
    }

    private function processConnections(ContainerBuilder $container, array $config)
    {
        $connections = $config['connections'];
        $appName     = $config['app_name'];

        if (count($connections) > 0) {
            foreach ($connections as $name => $connection) {
                $version = $connection['version'];
                // Alias Auth Provider Cache
                $cacheProviderId = "doctrine_cache.providers.{$connection['config']['cache']['auth_provider']}";
                $container->setAlias("ae_connect.connection.$name.cache.auth_provider", $cacheProviderId);
                $replayCacheProviderId = "doctrine_cache.providers.{$connection['config']['cache']['replay_provider']}";
                $container->setAlias("ae_connect.connection.$name.cache.replay_extension", $replayCacheProviderId);

                if (isset($connection['login']['entity'])) {
                    $this->createMetadataRegistryService(
                        $connection,
                        $name,
                        $container,
                        $config['default_connection'] === $name
                    );

                    $proxy = $container->register('ae_connect.connection_proxy.'.$name, ConnectionProxy::class)
                                       ->addMethodCall('setName', [$name])
                                       ->addMethodCall('setConfig', [$connection + ['app_name' => $appName]])
                                       ->addMethodCall(
                                           'setMetadataRegistry',
                                           [new Reference("ae_connect.connection.$name.metadata_registry")]
                                       )
                                       ->addMethodCall(
                                           'setCache',
                                           [new Reference($cacheProviderId)]
                                       )
                                       ->addTag('ae_connect.connection_proxy')
                    ;

                    if ($container->hasDefinition($connection['config']['connection_logger'])) {
                        $proxy->addMethodCall('setLogger', new Reference($connection['config']['connection_logger']));
                    }
                } else {
                    $this->createAuthProviderService(
                        $connection['login'],
                        $name,
                        $container,
                        $connection['config']['connection_logger']
                    );
                    $this->createBayeuxClientService($name, $container, $version);
                    $this->createStreamingClientService($name, $connection, $container);
                    $this->createRestClientService($name, $container, $version, $appName);
                    $this->createBulkClientExtension($name, $container, $version);
                    $this->createReplayExtensionService($connection, $name, $replayCacheProviderId, $container);
                    $this->createMetadataRegistryService(
                        $connection,
                        $name,
                        $container,
                        $config['default_connection'] === $name
                    );
                    $this->createConnectionService(
                        $name,
                        $name === $config['default_connection'],
                        $container,
                        $connection['config']['bulk_api_min_count'],
                        $appName,
                        $connection['config']['app_filtering']['enabled'],
                        $connection['config']['app_filtering']['permitted_objects']
                    );

                    if ($name !== "default" && $name === $config['default_connection']) {
                        $container->setAlias("ae_connect.connection.default", new Alias("ae_connect.connection.$name"));
                    }
                }
            }
        }
    }

    private function createAuthProviderService(
        array $config,
        string $connectionName,
        ContainerBuilder $container,
        ?string $logger = null
    ) {
        if (array_key_exists('key', $config) && array_key_exists('secret', $config)) {
            $proxy = $container->register("ae_connect.connection.$connectionName.auth_provider", OAuthProvider::class)
                               ->setArguments(
                                   [
                                       new Reference("ae_connect.connection.$connectionName.cache.auth_provider"),
                                       $config['key'],
                                       $config['secret'],
                                       $config['url'],
                                       $config['username'],
                                       $config['password'],
                                   ]
                               )
                               ->setPublic(true)
            ;
        } else {
            $proxy = $container->register("ae_connect.connection.$connectionName.auth_provider", SoapProvider::class)
                               ->setArguments(
                                   [
                                       new Reference("ae_connect.connection.$connectionName.cache.auth_provider"),
                                       $config['username'],
                                       $config['password'],
                                       $config['url'],
                                   ]
                               )
                               ->setPublic(true)
            ;
        }

        if (in_array(LoggerAwareInterface::class, class_implements($proxy->getClass(), true)) &&
            $container->hasDefinition($logger)) {
            $proxy->addMethodCall('setLogger', new Reference($logger));
        }
    }

    private function createStreamingClientService(
        string $connectionName,
        array $config,
        ContainerBuilder $container
    ) {
        $def = $container->register("ae_connect.connection.$connectionName.streaming_client", Client::class)
                         ->setArgument('$client', new Reference("ae_connect.connection.$connectionName.bayeux_client"))
        ;

        if (!empty($config['topics'])) {
            $this->buildTopics($connectionName, $config['topics'], $container, $def);
        }

        if (!empty($config['platform_events'])) {
            $this->buildPlatformEvents($connectionName, $config['platform_events'], $container, $def);
        }

        if (!empty($config['generic_events'])) {
            $this->buildGenericEvents($connectionName, $config['generic_events'], $container, $def);
        }

        if (!empty($config['objects'])) {
            $this->buildObjects(
                $connectionName,
                $config['objects'],
                $container,
                $def,
                $config['config']['use_change_data_capture']
            );
        }

        if (!empty($config['change_events'])) {
            $this->buildChangeEvents($connectionName, $config['change_events'], $container, $def);
        }

        if (!empty($config['polling'])) {
            $this->buildPolling($connectionName, $config['polling'], $container);
        }
    }

    private function createTopic(array $config): Definition
    {
        $topic = new Definition(Topic::class);

        $topic->addMethodCall('setName', [$config['name']]);
        $topic->addMethodCall('setFilters', [$config['filter']]);
        $topic->addMethodCall('setType', [$config['type']]);

        return $topic;
    }

    /**
     * @param string $name
     * @param array $config
     * @param ContainerBuilder $container
     * @param Definition $def
     */
    private function buildTopics(string $name, array $config, ContainerBuilder $container, Definition $def): void
    {
        foreach ($config as $topicName => $topicConfig) {
            $topicConfig['name'] = $topicName;
            $topic               = $this->createTopic($topicConfig);
            $topicId             = "ae_connect.connection.$name.topic.$topicName";

            $container->setDefinition($topicId, $topic);

            $def->addMethodCall('addSubscriber', [new Reference($topicId)]);
        }
    }

    /**
     * @param string $name
     * @param array $config
     * @param ContainerBuilder $container
     * @param Definition $def
     */
    private function buildPlatformEvents(
        string $name,
        array $config,
        ContainerBuilder $container,
        Definition $def
    ): void {
        foreach ($config as $eventName) {
            $event   = new Definition(PlatformEvent::class, [$eventName]);
            $eventId = "ae_connect.connection.$name.platform_event.$eventName";

            $container->setDefinition($eventId, $event);

            $def->addMethodCall('addSubscriber', [new Reference($eventId)]);
        }
    }

    /**
     * @param string $name
     * @param array $config
     * @param ContainerBuilder $container
     * @param Definition $def
     * @param bool $useCdc
     *
     * @deprecated
     */
    private function buildObjects(
        string $name,
        array $config,
        ContainerBuilder $container,
        Definition $def,
        bool $useCdc = true
    ): void {
        $pollObjects   = [];
        $changeObjects = [];

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
                $changeObjects[] = $objectName;
            } else {
                $pollObjects[] = $objectName;
            }

            if (!empty($changeObjects)) {
                $this->buildChangeEvents($name, $changeObjects, $container, $def);
            }

            if (!empty($pollObjects)) {
                $this->buildPolling($name, $pollObjects, $container);
            }
        }
    }

    private function buildChangeEvents(string $name, array $objects, ContainerBuilder $container, Definition $def)
    {
        foreach ($objects as $objectName) {
            if ((preg_match('/__(c|C)$/', $objectName) == true
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
                $event   = new Definition(ChangeEvent::class, [$objectName]);
                $eventId = "ae_connect.connection.$name.change_event.$objectName";

                $container->setDefinition($eventId, $event);

                $def->addMethodCall('addSubscriber', [new Reference($eventId)]);
            }
        }
    }

    private function buildPolling(string $name, array $objects, ContainerBuilder $container)
    {
        $pollObjects = $container->hasParameter('ae_connect.poll_objects')
            ? $container->getParameter('ae_connect.poll_objects')
            : [];

        foreach ($objects as $object) {
            $pollObjects[$name][] = $object;
        }

        $container->setParameter('ae_connect.poll_objects', $pollObjects);
    }

    /**
     * @param string $name
     * @param array $config
     * @param ContainerBuilder $container
     * @param Definition $def
     */
    private function buildGenericEvents(string $name, array $config, ContainerBuilder $container, Definition $def): void
    {
        foreach ($config as $eventName) {
            $event   = new Definition(GenericEvent::class, [$eventName]);
            $eventId = "ae_connect.connection.$name.generic_event.$eventName";

            $container->setDefinition($eventId, $event);

            $def->addMethodCall('addSubscriber', [new Reference($eventId)]);
        }
    }

    /**
     * @param string $connectionName
     * @param ContainerBuilder $container
     * @param string $version
     */
    private function createBayeuxClientService(
        string $connectionName,
        ContainerBuilder $container,
        string $version
    ): void {
        $container->register("ae_connect.connection.$connectionName.bayeux_client", BayeuxClient::class)
                  ->setArgument('$authProvider', new Reference("ae_connect.connection.$connectionName.auth_provider"))
                  ->setArgument('$version', $version)
                  ->setAutowired(true)
        ;
    }

    /**
     * @param string $connectionName
     * @param ContainerBuilder $container
     * @param string $version
     * @param string|null $appName
     */
    private function createRestClientService(
        string $connectionName,
        ContainerBuilder $container,
        string $version,
        ?string $appName = null
    ): void {
        $container->register(
            "ae_connect.connection.$connectionName.rest_client",
            \AE\SalesforceRestSdk\Rest\Client::class
        )
                  ->setArgument('$provider', new Reference("ae_connect.connection.$connectionName.auth_provider"))
                  ->setArgument('$version', $version)
                  ->setArgument('$appName', $appName)
                  ->setAutowired(true)
        ;
    }

    /**
     * @param string $connectionName
     * @param ContainerBuilder $container
     * @param string $version
     */
    private
    function createBulkClientExtension(
        string $connectionName,
        ContainerBuilder $container,
        string $version
    ): void {
        $container->register(
            "ae_connect.connection.$connectionName.bulk_client",
            \AE\SalesforceRestSdk\Bulk\Client::class
        )
                  ->setArgument('$authProvider', new Reference("ae_connect.connection.$connectionName.auth_provider"))
                  ->setArgument('$version', $version)
                  ->setAutowired(true)
        ;
    }

    /**
     * @param array $config
     * @param string $connectionName
     * @param string $replayCacheId
     * @param ContainerBuilder $container
     */
    private
    function createReplayExtensionService(
        array $config,
        string $connectionName,
        string $replayCacheId,
        ContainerBuilder $container
    ): void {
        $container->register("ae_connect.connection.$connectionName.replay_extension", ReplayExtension::class)
                  ->setArguments(
                      [
                          new Reference($replayCacheId),
                          $config['config']['replay_start_id'],
                      ]
                  )
                  ->addTag('ae_connect.extension', ['connections' => $connectionName])
                  ->setPrivate(false)
        ;
    }

    /**
     * @param array $config
     * @param string $connectionName
     * @param ContainerBuilder $container
     * @param bool $isDefault
     */
    private
    function createMetadataRegistryService(
        array $config,
        string $connectionName,
        ContainerBuilder $container,
        bool $isDefault
    ): void {
        $cacheProviderId = "doctrine_cache.providers.{$config['config']['cache']['metadata_provider']}";
        $container->setAlias("ae_connect.connection.$connectionName.cache.metadata_provider", $cacheProviderId);
        $container->register("ae_connect.connection.$connectionName.metadata_registry", MetadataRegistry::class)
                  ->setArguments(
                      [
                          new Reference("ae_connect.annotation_driver"),
                          new Reference("ae_connect.connection.$connectionName.cache.metadata_provider"),
                          $connectionName,
                          $isDefault,
                      ]
                  )
                  ->setFactory([MetadataRegistryFactory::class, 'generate'])
        ;
    }

    /**
     * @param string $connectionName
     * @param bool $isDefault
     * @param ContainerBuilder $container
     * @param int $bulkQueryMinCount
     * @param string|null $appName
     * @param bool $appFilteringEnabled ,
     * @param array $permittedObjects
     */
    private function createConnectionService(
        string $connectionName,
        bool $isDefault,
        ContainerBuilder $container,
        int $bulkQueryMinCount = 100000,
        ?string $appName = null,
        bool $appFilteringEnabled = true,
        array $permittedObjects = []
    ): void {
        $container->register("ae_connect.connection.$connectionName", Connection::class)
                  ->setArguments(
                      [
                          '$name'             => $connectionName,
                          '$streamingClient'  => new Reference(
                              "ae_connect.connection.$connectionName.streaming_client"
                          ),
                          '$restClient'       => new Reference(
                              "ae_connect.connection.$connectionName.rest_client"
                          ),
                          '$bulkClient'       => new Reference(
                              "ae_connect.connection.$connectionName.bulk_client"
                          ),
                          '$metadataRegistry' => new Reference(
                              "ae_connect.connection.$connectionName.metadata_registry"
                          ),
                          '$isDefault'        => $isDefault,
                          '$bulkApiMinCount'  => $bulkQueryMinCount,
                      ]
                  )
                  ->setPublic(true)
                  ->setAutowired(true)
                  ->setAutoconfigured(true)
                  ->addMethodCall('setAppName', [$appName])
                  ->addMethodCall('setAppFilteringEnabled', [$appFilteringEnabled])
                  ->addMethodCall('setPermittedFilteredObjects', [$permittedObjects])
                  ->addTag('ae_connect.connection')
        ;
    }
}
