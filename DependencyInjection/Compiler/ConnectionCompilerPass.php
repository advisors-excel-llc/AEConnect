<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/13/18
 * Time: 10:41 AM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use AE\ConnectBundle\Manager\ConnectionManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ConnectionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition(ConnectionManager::class)) {
            $manager = $container->getDefinition(ConnectionManager::class);
            $tags = $container->findTaggedServiceIds('ae_connect.connection');

            foreach (array_keys($tags) as $id) {
                $manager->addMethodCall('registerConnection', [new Reference($id)]);
            }

            $container->setDefinition(ConnectionManager::class, $manager);
        }
    }
}
