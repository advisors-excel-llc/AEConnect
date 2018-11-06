<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 11:06 AM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use AE\ConnectBundle\Driver\DbalConnectionDriver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ConnectionProxyCompilerPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition(DbalConnectionDriver::class)) {
            $driver = $container->getDefinition(DbalConnectionDriver::class);
            $tags = $container->findTaggedServiceIds('ae_connect.connection_proxy');

            foreach (array_keys($tags) as $id) {
                $driver->addMethodCall('addConnectionProxy', [new Reference($id)]);
            }
        }
    }
}
