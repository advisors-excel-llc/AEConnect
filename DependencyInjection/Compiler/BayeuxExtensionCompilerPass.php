<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:07 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class BayeuxExtensionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
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
}
