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

class ChannelConsumerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.consumer');

        foreach ($tags as $id => $attributes) {
            if (array_key_exists('connections', $attributes) && strlen($attributes['connections']) > 0) {
                $connections = explode(",", $attributes['connections']);
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = $config[0]['connections'];
            }

            $subscriber = $container->getDefinition($id);

            foreach (array_keys($connections) as $name) {
                $name = trim($name);

                if ($container->hasDefinition("ae_connect.connection.$name.streaming_client")) {
                    $service = $container->getDefinition("ae_connect.connection.$name.streaming_client");
                    $service->addMethodCall('subscribe', [new Reference($id)]);

                    if (method_exists($subscriber->getClass(), 'addConnection')) {
                        $subscriber->addMethodCall(
                            'addConnection',
                            [new Reference("ae_connect.connection.$name")]
                        );
                    }

                    if (method_exists($subscriber->getClass(), 'setConnection')) {
                        $subscriber->addMethodCall(
                            'setConnection',
                            [new Reference("ae_connect.connection.$name")]
                        );
                    }
                }
            }
        }
    }
}
