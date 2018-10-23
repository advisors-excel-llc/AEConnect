<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle;

use AE\ConnectBundle\DependencyInjection\Compiler\BayeuxExtensionCompilerPass;
use AE\ConnectBundle\DependencyInjection\Compiler\ChannelConsumerCompilerPass;
use AE\ConnectBundle\DependencyInjection\Compiler\ConnectionCompilerPass;
use AE\ConnectBundle\DependencyInjection\Compiler\PolllingCompilerPass;
use AE\ConnectBundle\DependencyInjection\Compiler\TransformerPluginCompilerPass;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AEConnectBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new ConnectionCompilerPass());
        $container->addCompilerPass(new PolllingCompilerPass());
        $container->addCompilerPass(new BayeuxExtensionCompilerPass());
        $container->addCompilerPass(new ChannelConsumerCompilerPass());
        $container->addCompilerPass(new TransformerPluginCompilerPass());
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
