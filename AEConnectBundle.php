<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/5/18
 * Time: 5:44 PM
 */

namespace AE\ConnectBundle;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\DependencyInjection\Compiler\CompilerPass;
use AE\ConnectBundle\Driver\AnnotationDriver;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\SalesforceRestSdk\Rest\Composite\Builder\CompositeRequestBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class AEConnectBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new CompilerPass());
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \ReflectionException
     */
    public function boot()
    {
        $this->bootAnnotations();
        $this->bootMetadata();
    }

    /**
     * @param ConnectionInterface $connection
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function fetchMetadataDescribe(ConnectionInterface $connection): void
    {
        $name             = $connection->getName();
        $builder          = new CompositeRequestBuilder();
        $metadataRegistry = $connection->getMetadataRegistry();

        foreach ($metadataRegistry->getMetadata() as $metadatum) {
            $builder->describe("{$name}_{$metadatum->getClassName()}", $metadatum->getSObjectType());
        }

        $response = $connection->getRestClient()->getCompositeClient()->sendCompositeRequest($builder->build());

        foreach ($metadataRegistry->getMetadata() as $metadatum) {
            $result = $response->findResultByReferenceId("{$name}_{$metadatum->getClassName()}");
            if (null !== $result && 200 === $result->getHttpStatusCode()) {
                $metadatum->setDescribe($result->getBody());
            }
        }
    }

    /**
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function bootMetadata(): void
    {
        /** @var ConnectionManagerInterface $manager */
        $manager     = $this->container->get('ae_connect.connection_manager');
        // Prevent the default connection to be run twice if it's configured name is not 'default'
        $connections = array_filter($manager->getConnections(), function (ConnectionInterface $val, $key) {
            return $key === 'default' || !$val->isDefault();
        }, ARRAY_FILTER_USE_BOTH);

        foreach ($connections as $connection) {
            $this->fetchMetadataDescribe($connection);
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function bootAnnotations(): void
    {
        /** @var AnnotationDriver $driver */
        $driver = $this->container->get('ae_connect.annotation_driver');
        foreach ($driver->getAllClassNames() as $className) {
            $driver->loadMetadataForClass($className);
        }
    }
}
