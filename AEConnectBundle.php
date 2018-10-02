<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle;

use AE\ConnectBundle\DependencyInjection\Compiler\CompilerPass;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AEConnectBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new CompilerPass());
    }

    public function boot()
    {
        /** @var ConnectionManagerInterface $manager */
        $manager = $this->container->get('ae_connect.connection_manager');
        $connections = $manager->getConnections();

        if (null !== $connections) {
            foreach ($connections as $connection) {
                $connection->hydrateMetadataDescribe();
            }
        }
    }
}
