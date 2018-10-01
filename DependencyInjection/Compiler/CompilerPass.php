<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:53 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // TODO: Get Transformer service and register any services with the ae_connect.transformer tag with it
        $this->processBayeuxExtensions($container);
        $this->processChannelSubscribers($container);
    }

    private function processBayeuxExtensions(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.extension');

        foreach ($tags as $id => $attributes) {
            if (array_key_exists('connections', $attributes) && !empty($attributes['connections'])) {
                $connections = explode(",", $attributes['connections']);
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = array_keys($config[0]['connections']);
            }

            foreach ($connections as $connection) {
                $connection = trim($connection);
                if ($container->hasDefinition("ae_connect.connection.$connection.bayeux_client")) {
                    $service = $container->getDefinition("ae_connect.connection.$connection.bayeux_client");
                    $service->addMethodCall('addExtension', [new Reference($id)]);
                }
            }
        }
    }

    private function processChannelSubscribers(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.subscriber');

        foreach ($tags as $id => $attributes) {
            if (!array_key_exists('channel', $attributes) || empty($attributes['channel'])) {
                throw new \RuntimeException("The 'channel' attribute must be set on the consumer's service tag.");
            }

            if (array_key_exists('connections', $attributes) && strlen($attributes['connections']) > 0) {
                $connections = explode(",", $attributes['connections']);
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = array_keys($config[0]['connections']);
            }

            $subscriber = $container->getDefinition($id);

            foreach ($connections as $connection) {
                $connection = trim($connection);
                if ($container->hasDefinition("ae_connect.connection.$connection")) {
                    $service = $container->getDefinition("ae_connect.connection.$connection");
                    $service->addMethodCall('subscribe', [$attributes['topic'], new Reference($id)]);

                    if ($subscriber->hasMethodCall('addConnection')) {
                        $subscriber->addMethodCall(
                            'addConnection',
                            [new Reference("ae_connect.connection.$connection")]
                        );
                    }

                    if ($subscriber->hasMethodCall('setConnection')) {
                        $subscriber->addMethodCall(
                            'setConnection',
                            [new Reference("ae_connect.connection.$connection")]
                        );
                    }
                }
            }
        }
    }
}
