<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/23/18
 * Time: 8:21 AM
 */

namespace AE\ConnectBundle\DependencyInjection\Compiler;

use AE\ConnectBundle\Salesforce\Inbound\Polling\PollingService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PolllingCompilerPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition(PollingService::class)) {
            $def         = $container->getDefinition(PollingService::class);
            $pollObjects = $container->hasParameter('ae_connect.poll_objects')
                ? $container->getParameter('ae_connect.poll_objects')
                : [];

            foreach ($pollObjects as $connectionName => $objects) {
                foreach ($objects as $object) {
                    $def->addMethodCall('registerObject', [$object, $connectionName]);
                }
            }
        }
    }
}
