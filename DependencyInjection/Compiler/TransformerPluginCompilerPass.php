<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:09 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TransformerPluginCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition(Transformer::class)) {
            $transformer = $container->getDefinition(Transformer::class);
            $services    = $container->findTaggedServiceIds('ae_connect.transformer_plugin');

            foreach (array_keys($services) as $id) {
                $transformer->addMethodCall('registerPlugin', [new Reference($id)]);
            }
        }
    }
}
