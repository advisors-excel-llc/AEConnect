<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/9/19
 * Time: 4:24 PM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class EnqueueCompilerPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition('enqueue.transport.ae_connect.queue_consumer')) {
            $def = $container->getDefinition('enqueue.transport.ae_connect.queue_consumer');
            $def->setPrivate(false);
            $container->setDefinition('enqueue.transport.ae_connect.queue_consumer', $def);
        }

        if ($container->hasDefinition('enqueue.transport.ae_connect.processor_registry')) {
            $def = $container->getDefinition('enqueue.transport.ae_connect.processor_registry');
            $def->setPrivate(false);
            $container->setDefinition('enqueue.transport.ae_connect.processor_registry', $def);
        }
    }
}
