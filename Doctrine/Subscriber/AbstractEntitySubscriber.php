<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 8/27/19
 * Time: 11:52 AM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractEntitySubscriber implements EntitySubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var SalesforceConnector
     */
    protected $connector;

    /**
     * @var ConnectionManagerInterface
     */
    protected $connectionManager;

    /**
     * @var SObjectCompiler
     */
    protected $compiler;

    public function __construct(
        SalesforceConnector $connector,
        ConnectionManagerInterface $connectionManager,
        SObjectCompiler $compiler
    ) {
        $this->connector         = $connector;
        $this->connectionManager = $connectionManager;
        $this->compiler          = $compiler;
        $this->logger            = new NullLogger();
    }

    abstract protected function getUpserts(): array;

    abstract protected function getRemovals(): array;

    abstract protected function getProcessing(): array;

    abstract protected function saveUpserts(array $upserts);

    abstract protected function saveRemovals(array $removals);

    abstract protected function saveProcessing(array $processing);

    abstract protected function clearUpserts();

    abstract protected function clearRemovals();

    abstract protected function clearProcessing();

    public function getSubscribedEvents()
    {
        return [
            Events::prePersist,
            Events::postPersist,
            Events::preUpdate,
            Events::postUpdate,
            Events::preRemove,
            Events::postRemove,
            Events::postFlush,
            Events::onClear,
        ];
    }

    public function prePersist(LifecycleEventArgs $event)
    {
        $entity = $event->getObject();
        $this->upsertEntity($entity);
    }

    public function postPersist(LifecycleEventArgs $event)
    {
        $this->flushUpserts();
    }

    public function preUpdate(LifecycleEventArgs $event)
    {
        $entity = $event->getObject();
        $this->upsertEntity($entity);
    }

    public function postUpdate(LifecycleEventArgs $event)
    {
        $this->flushUpserts();
    }

    public function preRemove(LifecycleEventArgs $event)
    {
        $entity = $event->getObject();
        $this->removeEntity($entity);
    }

    public function postRemove(LifecycleEventArgs $event)
    {
        $this->flushRemovals();
    }

    public function postFlush(PostFlushEventArgs $event)
    {
        $this->clearProcessing();
        $this->clearUpserts();
        $this->clearRemovals();
    }

    public function onClear(OnClearEventArgs $event)
    {
        $this->clearProcessing();
        $this->clearUpserts();
        $this->clearRemovals();
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
    protected function upsertEntity($entity): void
    {
        if ($entity instanceof SalesforceIdEntityInterface) {
            return;
        }

        $entities = $this->getUpserts();

        $oid = \spl_object_hash($entity);

        if (!array_key_exists($oid, $entities)) {
            $entities[$oid] = $entity;
        }

        $this->saveUpserts($entities);
    }

    /**
     * @param $entity
     */
    protected function removeEntity($entity): void
    {
        if ($entity instanceof SalesforceIdEntityInterface) {
            return;
        }

        $entities = $this->getRemovals();

        $oid = \spl_object_hash($entity);

        if (!array_key_exists($oid, $entities)) {
            $entities[$oid] = $entity;
        }

        $this->saveRemovals($entities);
    }

    protected function flushUpserts(): void
    {
        $entities = $this->getUpserts();

        if (empty($entities)) {
            return;
        }

        $processing  = $this->getProcessing();
        $connections = $this->connectionManager->getConnections();

        foreach ($connections as $connection) {
            foreach ($entities as $oid => $entity) {
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

        $this->saveProcessing($processing);
        $this->saveUpserts($entities);
    }

    protected function flushRemovals(): void
    {
        $entities = $this->getRemovals();

        if (empty($entities)) {
            return;
        }

        $processing  = $this->getProcessing();
        $connections = $this->connectionManager->getConnections();

        foreach ($connections as $connection) {
            foreach ($entities as $oid => $entity) {
                try {
                    if (null === $connection->getMetadataRegistry()->findMetadataForEntity($entity)) {
                        continue;
                    }

                    if (array_key_exists($oid, $processing)) {
                        continue;
                    }

                    $processing[$oid] = $oid;
                    $result           = $this->compiler->compile($entity, $connection->getName());
                    $result->setIntent(CompilerResult::DELETE);
                    if (null !== $result->getSObject()->Id) {
                        $this->connector->sendCompilerResult($result);
                    }
                } catch (\RuntimeException $e) {
                    // If the entity isn't able to be sent to Salesforce for any reason,
                    // a RuntimeException is thrown. We don't want that stopping our fun.
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                    unset($processing[$oid]);
                } finally {
                    unset($entities[$oid]);
                }
            }
        }

        $this->saveProcessing($processing);
        $this->saveUpserts($entities);
    }
}
