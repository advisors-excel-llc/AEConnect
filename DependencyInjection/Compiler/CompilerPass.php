<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:53 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use AE\ConnectBundle\Bayeux\BayeuxClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // TODO: Get Transformer service and register any services with the ae_connect.transformer tag with it
        $this->processBayeuxExtensions($container);
    }

    private function processBayeuxExtensions(ContainerBuilder $container)
    {
        if ($container->hasDefinition(BayeuxClient::class)) {
            $client = $container->getDefinition(BayeuxClient::class);
            $tags   = $container->findTaggedServiceIds('ae_connect.extension');

            foreach ($tags as $id => $attributes) {
                $client->addMethodCall('addExtension', [new Reference($id)]);
            }
        }
    }
}
