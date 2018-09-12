<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle\DependencyInjection;

use AE\ConnectBundle\AuthProvider\LoginProvider;
use AE\ConnectBundle\Bayeux\BayeuxClient;
use AE\ConnectBundle\Connection\Connection;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
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

        $this->processConnections($container, $config);
    }

    private function processConnections(ContainerBuilder $container, array $config)
    {
        $connections = $config['connections'];

        if (count($connections) > 0) {
            $manager = $container->getDefinition(ConnectionManagerInterface::class);

            foreach ($connections as $name => $connection) {
                $authProvider = $this->createAuthProviderService($connection['login']);
                $container->setDefinition("ae_connect.connection.$name.auth_provider", $authProvider);

                $bayeuxClient = new Definition(
                    BayeuxClient::class,
                    [
                        '$url'          => $connection['url'],
                        '$authProvider' => new Reference("ae_connect.connection.$name.auth_provider"),
                    ]
                );
                $bayeuxClient->setAutowired(true);

                $container->setDefinition("ae_connect.connection.$name.bayeux_client", $bayeuxClient);
                $container->setDefinition(
                    "ae_connect.connection.$name.streaming_client",
                    $this->createStreamingClientService($connection['topics'])
                );

                $container->setDefinition(
                    "ae_connect.connection.$name",
                    new Definition(
                        Connection::class,
                        [
                            new Reference(
                                "ae_connect.connection.$name.streaming_client"
                            ),
                        ]
                    )
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

    private function createStreamingClientService(array $config): Definition
    {
        $def = new Definition(
            Client::class,
            [
                new Reference("ae_connect.connection.$name.bayeux_client"),
            ]
        );

        foreach ($config as $name => $topicConfig) {
            $topicConfig['name'] = $name;
            $topic               = $this->createTopic($topicConfig);

            $def->addMethodCall('addTopic', [$topic]);
        }

        return $def;
    }

    private function createTopic(array $config): Topic
    {
        $topic = new Topic();

        $topic->setName($config['name']);
        $topic->setFilters($config['filter']);
        $topic->setApiVersion($config['api_version']);
        $topic->setAutoCreate($config['create_if_not_exists']);
        $topic->setNotifyForOperationCreate($config['create']);
        $topic->setNotifyForOperationUpdate($config['update']);
        $topic->setNotifyForOperationUndelete($config['undelete']);
        $topic->setNotifyForOperationDelete($config['delete']);
        $topic->setNotifyForFields($config['notify_for_fields']);

        return $topic;
    }
}
