<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle\DependencyInjection;

use AE\ConnectBundle\Connection\Connection;
use AE\ConnectBundle\Driver\AnnotationDriver;
use AE\ConnectBundle\Metadata\MetadataRegistry;
use AE\ConnectBundle\Metadata\MetadataRegistryFactory;
use AE\ConnectBundle\Streaming\ChangeEvent;
use AE\ConnectBundle\Streaming\GenericEvent;
use AE\ConnectBundle\Streaming\PlatformEvent;
use AE\SalesforceRestSdk\AuthProvider\LoginProvider;
use AE\SalesforceRestSdk\Bayeux\BayeuxClient;
use AE\SalesforceRestSdk\Bayeux\Extension\ReplayExtension;
use AE\ConnectBundle\Manager\ConnectionManager;
use AE\ConnectBundle\Streaming\Client;
use AE\ConnectBundle\Streaming\Topic;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class AEConnectExtension extends Extension
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
        $loader->load('services.yml');
        $loader->load('transformers.yml');

        $config = $this->processConfiguration(new Configuration\Configuration(), $configs);

        $this->createAnnotationDriver($container, $config);
        $this->processConnections($container, $config);
    }

    private function createAnnotationDriver(ContainerBuilder $container, array $config)
    {
        $definition = new Definition(AnnotationDriver::class, [new Reference("annotation_reader"), $config['paths']]);

        $container->setDefinition("ae_connect.annotation_driver", $definition);
    }

    private function processConnections(ContainerBuilder $container, array $config)
    {
        $connections = $config['connections'];

        if (count($connections) > 0) {
            $manager = $container->findDefinition(ConnectionManager::class);

            foreach ($connections as $name => $connection) {
                $authProvider = $this->createAuthProviderService($connection['login']);
                $container->setDefinition("ae_connect.connection.$name.auth_provider", $authProvider);

                $bayeuxClient = new Definition(
                    BayeuxClient::class,
                    [
                        '$authProvider' => new Reference("ae_connect.connection.$name.auth_provider"),
                    ]
                );
                $bayeuxClient->setAutowired(true);

                $container->setDefinition("ae_connect.connection.$name.bayeux_client", $bayeuxClient);
                $container->setDefinition(
                    "ae_connect.connection.$name.streaming_client",
                    $this->createStreamingClientService($name, $connection, $container)
                );

                $restClient = new Definition(
                    \AE\SalesforceRestSdk\Rest\Client::class,
                    [
                        '$provider' => new Reference("ae_connect.connection.$name.auth_provider"),
                    ]
                );
                $restClient->setAutowired(true);

                $container->setDefinition(
                    "ae_connect.connection.$name.rest_client",
                    $restClient
                );

                $bulkClient = new Definition(
                    \AE\SalesforceRestSdk\Bulk\Client::class,
                    [
                        '$authProvider' => new Reference("ae_connect.connection.$name.auth_provider"),
                    ]
                );
                $bulkClient->setAutowired(true);

                $container->setDefinition(
                    "ae_connect.connection.$name.bulk_client",
                    $bulkClient
                );

                $container->setDefinition(
                    "ae_connect.connection.$name.replay_extension",
                    $this->createReplayExtension($name, $connection['config']['replay_start_id'])
                );

                $cacheProviderId = "doctrine_cache.providers.{$connection['config']['cache']['metadata_provider']}";
                $container->setAlias("ae_connect.connection.$name.cache.metadata_provider", $cacheProviderId);

                $registryDef = new Definition(
                    MetadataRegistry::class,
                    [
                        new Reference("ae_connect.annotation_driver"),
                        new Reference("ae_connect.connection.$name.cache.metadata_provider"),
                        $name,
                    ]
                );
                $registryDef->setFactory([MetadataRegistryFactory::class, 'generate']);

                $container->setDefinition("ae_connect.connection.$name.metadata_registry", $registryDef);

                $connectionDef = new Definition(
                    Connection::class,
                    [
                        '$name'             => $name,
                        '$streamingClient'  => new Reference(
                            "ae_connect.connection.$name.streaming_client"
                        ),
                        '$restClient'       => new Reference(
                            "ae_connect.connection.$name.rest_client"
                        ),
                        '$bulkClient'       => new Reference(
                            "ae_connect.connection.$name.bulk_client"
                        ),
                        '$metadataRegistry' => new Reference(
                            "ae_connect.connection.$name.metadata_registry"
                        ),
                        '$isDefault'        => $connection['is_default'],
                    ]
                );
                $connectionDef->setPublic(true);
                $connectionDef->setAutowired(true);

                $container->setDefinition(
                    "ae_connect.connection.$name",
                    $connectionDef
                );

                $manager->addMethodCall('registerConnection', [$name, new Reference("ae_connect.connection.$name")]);

                if ($name !== "default" && $connection['is_default']) {
                    $container->setAlias("ae_connect.connection.default", new Alias("ae_connect.connection.$name"));
                    $manager->addMethodCall(
                        'registerConnection',
                        ['default', new Reference("ae_connect.connection.default")]
                    );
                }
            }
        }
    }

    private function createAuthProviderService(array $config): Definition
    {
        return new Definition(
            LoginProvider::class,
            [
                $config['key'],
                $config['secret'],
                $config['username'],
                $config['password'],
                $config['url'],
            ]
        );
    }

    private function createStreamingClientService(string $name, array $config, ContainerBuilder $container): Definition
    {
        $def = new Definition(
            Client::class,
            [
                new Reference("ae_connect.connection.$name.bayeux_client"),
            ]
        );

        if (!empty($config['topics'])) {
            $this->buildTopics($name, $config['topics'], $container, $def);
        }

        if (!empty($config['platform_events'])) {
            $this->buildPlatformEvents($name, $config['platform_events'], $container, $def);
        }

        if (!empty($config['generic_events'])) {
            $this->buildGenericEvents($name, $config['generic_events'], $container, $def);
        }

        if (!empty($config['objects'])) {
            $this->buildObjects($name, $config['objects'], $container, $def);
        }

        return $def;
    }

    private function createReplayExtension(string $name, int $replayId): Definition
    {
        $def = new Definition(
            ReplayExtension::class,
            [
                $replayId,
            ]
        );

        $def->addTag('ae_connect.extension', ['connections' => $name]);

        return $def;
    }

    private function createTopic(array $config): Definition
    {
        $topic = new Definition(Topic::class);

        $topic->addMethodCall('setName', [$config['name']]);
        $topic->addMethodCall('setFilters', [$config['filter']]);
        $topic->addMethodCall('setApiVersion', [$config['api_version']]);
        $topic->addMethodCall('setAutoCreate', [$config['create_if_not_exists']]);
        $topic->addMethodCall('setNotifyForOperationCreate', [$config['create']]);
        $topic->addMethodCall('setNotifyForOperationUpdate', [$config['update']]);
        $topic->addMethodCall('setNotifyForOperationUndelete', [$config['undelete']]);
        $topic->addMethodCall('setNotifyForOperationDelete', [$config['delete']]);
        $topic->addMethodCall('setNotifyForFields', [$config['notify_for_fields']]);

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

            $def->addMethodCall('addSubscriber', [new Reference([$eventId])]);
        }
    }

    /**
     * @param string $name
     * @param array $config
     * @param ContainerBuilder $container
     * @param Definition $def
     */
    private function buildObjects(string $name, array $config, ContainerBuilder $container, Definition $def): void
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
                $event   = new Definition(ChangeEvent::class, [$objectName]);
                $eventId = "ae_connect.connection.$name.change_event.$objectName";

                $container->setDefinition($eventId, $event);

                $def->addMethodCall('addSubscriber', [new Reference($eventId)]);
            } else {
                // TODO: add to scheduled polling service
            }
        }
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
}
