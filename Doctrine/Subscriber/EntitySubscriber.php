<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:44 PM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EntitySubscriber implements EventSubscriber, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var SObjectCompiler
     */
    private $compiler;

    /**
     * @var CacheProvider
     */
    private $cache;

    public function __construct(
        SalesforceConnector $connector,
        ConnectionManagerInterface $connectionManager,
        SObjectCompiler $compiler,
        CacheProvider $cache
    ) {
        $this->connector         = $connector;
        $this->connectionManager = $connectionManager;
        $this->compiler          = $compiler;
        $this->cache             = $cache;
        $this->logger            = new NullLogger();
    }

    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate',
            'postRemove',
            'onFlush',
            'postFlush',
            'onClear',
        ];
    }

    public function postPersist(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->upsertEntity($entity);
    }

    public function postUpdate(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->upsertEntity($entity);
    }

    public function postRemove(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->removeEntity($entity);
    }

    public function onFlush(OnFlushEventArgs $event)
    {
        $this->flushUpserts();
        $this->flushRemovals();
    }

    public function postFlush(PostFlushEventArgs $event)
    {
        $this->cache->save('processing', []);
    }

    public function onClear(OnClearEventArgs $event)
    {
        $this->cache->deleteAll();
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * @param $entity
     */
    private function upsertEntity($entity): void
    {
        if ($entity instanceof SalesforceIdEntityInterface) {
            return;
        }

        $entities = [];
        if ($this->cache->contains('upserts')) {
            $entities = $this->cache->fetch('upserts');
        }

        $oid = \spl_object_hash($entity);

        if (!array_key_exists($oid, $entities)) {
            $entities[$oid] = $entity;
        }

        $this->cache->save('upserts', $entities);
    }

    /**
     * @param $entity
     */
    private function removeEntity($entity): void
    {
        if ($entity instanceof SalesforceIdEntityInterface) {
            return;
        }

        $entities = [];
        if ($this->cache->contains('removals')) {
            $entities = $this->cache->fetch('removals');
        }

        $oid = \spl_object_hash($entity);

        if (!array_key_exists($oid, $entities)) {
            $entities[$oid] = $entity;
        }

        $this->cache->save('removals', $entities);
    }

    private function flushUpserts(): void
    {
        if (!$this->cache->contains('upserts')) {
            return;
        }

        $entities = $this->cache->fetch('upserts');

        if (empty($entities)) {
            return;
        }

        $processing = [];

        if ($this->cache->contains('processing')) {
            $processing = $this->cache->fetch('processing');
        }

        $connections = $this->connectionManager->getConnections();
        foreach ($connections as $connection) {
            foreach ($entities as $entity) {
                $oid = \spl_object_id($entity);
                try {
                    if (null === $connection->getMetadataRegistry()->findMetadataForEntity($entity)) {
                        continue;
                    }

                    if (array_key_exists($oid, $processing)) {
                        continue;
                    }

                    $processing[$oid] = $oid;
                    $this->connector->send($entity, $connection->getName());
                } catch (\RuntimeException $e) {
                    // If the entity isn't able to be sent to Salesforce for any reason,
                    // a RuntimeException is thrown. We don't want that stopping our fun.
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                    unset($processing[$oid]);
                }
                unset($entities[$oid]);
            }
        }

        $this->cache->save('upserts', $entities);
        $this->cache->save('processing', $processing);
    }

    private function flushRemovals(): void
    {
        if (!$this->cache->contains('removals')) {
            return;
        }

        $entities = $this->cache->fetch('removals');

        if (empty($entities)) {
            return;
        }
        $processing = [];

        if ($this->cache->contains('processing')) {
            $processing = $this->cache->fetch('processing');
        }

        $connections = $this->connectionManager->getConnections();
        foreach ($connections as $connection) {
            foreach ($entities as $entity) {
                $oid = \spl_object_id($entity);
                try {
                    if (null === $connection->getMetadataRegistry()->findMetadataForEntity($entity)) {
                        continue;
                    }

                    if (array_key_exists($oid, $processing)) {
                        continue;
                    }

                    $processing[$oid] = $oid;
                    $result = $this->compiler->compile($entity, $connection->getName());
                    $result->setIntent(CompilerResult::DELETE);
                    $this->connector->sendCompilerResult($result);
                } catch (\RuntimeException $e) {
                    // If the entity isn't able to be sent to Salesforce for any reason,
                    // a RuntimeException is thrown. We don't want that stopping our fun.
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                    unset($processing[$oid]);
                }
                unset($entities[$oid]);
            }
        }

        $this->cache->save('removals', $entities);
        $this->cache->save('processing', $processing);
    }
}
