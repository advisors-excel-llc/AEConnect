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
        $this->processTopicSubscribers($container);
    }

    private function processBayeuxExtensions(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.extension');

        foreach ($tags as $id => $attributes) {
            if (array_key_exists('connections', $attributes) && !empty($attributes['connections'])) {
                $connections = $attributes['connections'];
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = array_keys($config['connections']);
            }

            foreach ($connections as $connection) {
                if ($container->hasDefinition("ae_connect.connection.$connection.bayeux_client")) {
                    $service = $container->getDefinition("ae_connect.connection.$connection.bayeux_client");
                    $service->addMethodCall('addExtension', [new Reference($id)]);
                }
            }
        }
    }

    private function processTopicSubscribers(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.subscriber');

        foreach ($tags as $id => $attributes) {
            if (!array_key_exists('topic', $attributes) || empty($attributes['topic'])) {
                throw new \RuntimeException("The 'topic' attribute must be set on the consumer's service tag.");
            }

            if (array_key_exists('connections', $attributes) && !empty($attributes['connections'])) {
                $connections = $attributes['connections'];
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = array_keys($config['connections']);
            }

            foreach ($connections as $connection) {
                if ($container->hasDefinition("ae_connect.connection.$connection")) {
                    $service = $container->getDefinition("ae_connect.connection.$connection");
                    $service->addMethodCall('subscribe', [$attributes['topic'], new Reference($id)]);
                }
            }
        }
    }
}
