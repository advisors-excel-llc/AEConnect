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

class ChannelSubscriberCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $tags = $container->findTaggedServiceIds('ae_connect.subscriber');

        foreach ($tags as $id => $attributes) {
            if (!array_key_exists('channels', $attributes) || empty($attributes['channel'])) {
                throw new \RuntimeException("The 'channels' attribute must be set on the consumer's service tag.");
            }

            if (array_key_exists('connections', $attributes) && strlen($attributes['connections']) > 0) {
                $connections = explode(",", $attributes['connections']);
            } else {
                $config      = $container->getExtensionConfig('ae_connect');
                $connections = $config[0]['connections'];
            }

            $subscriber = $container->getDefinition($id);

            foreach ($connections as $name => $connection) {
                $name = trim($connection);
                $channels = explode(',', $attributes['channels']);

                if ($container->hasDefinition("ae_connect.connection.$name")) {
                    $service = $container->getDefinition("ae_connect.connection.$name");

                    foreach (array_keys($connection['topics']) as $topic) {
                        if (in_array($topic, $channels)) {
                            $service->addMethodCall('subscribe', ['/topic/'.$topic, new Reference($id)]);
                        }
                    }

                    foreach (array_keys($connection['platform_events']) as $topic) {
                        if (in_array($topic, $channels)) {
                            $service->addMethodCall('subscribe', ['/event/'.$topic, new Reference($id)]);
                        }
                    }

                    foreach (array_keys($connection['generic_events']) as $topic) {
                        if (in_array($topic, $channels)) {
                            $service->addMethodCall('subscribe', ['/u/'.$topic, new Reference($id)]);
                        }
                    }

                    foreach (array_keys($connection['objects']) as $topic) {
                        if (in_array($topic, $channels)) {
                            $topic = preg_replace('/__(c|C)$/', '__', $topic).'ChangeEvent';
                            $service->addMethodCall('subscribe', ['/data/'.$topic, new Reference($id)]);
                        }
                    }

                    if ($subscriber->hasMethodCall('addConnection')) {
                        $subscriber->addMethodCall(
                            'addConnection',
                            [new Reference("ae_connect.connection.$name")]
                        );
                    }

                    if ($subscriber->hasMethodCall('setConnection')) {
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
