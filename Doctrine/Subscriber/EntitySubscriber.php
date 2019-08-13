<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 4:44 PM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
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
     * @var array
     */
    private $entities = [];

    public function __construct(SalesforceConnector $connector, ConnectionManagerInterface $connectionManager)
    {
        $this->connector         = $connector;
        $this->connectionManager = $connectionManager;
        $this->logger            = new NullLogger();
    }

    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate',
            'postRemove',
            'preFlush',
        ];
    }

    public function postPersist(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->queueEntity($entity);
    }

    public function postUpdate(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->queueEntity($entity);
    }

    public function postRemove(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        $this->queueEntity($entity);
    }

    public function preFlush(PreFlushEventArgs $event)
    {
        $connections = $this->connectionManager->getConnections();
        foreach ($connections as $connection) {
            foreach ($this->entities as $entity) {
                try {
                    $this->connector->send($entity, $connection->getName());
                } catch (\RuntimeException $e) {
                    // If the entity isn't able to be sent to Salesforce for any reason,
                    // a RuntimeException is thrown. We don't want that stopping our fun.
                    $this->logger->error($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                }
            }
        }

        $this->entities = [];
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
    private function queueEntity($entity): void
    {
        $oid = \spl_object_hash($entity);

        if (!array_key_exists($oid, $this->entities)) {
            $this->entities[$oid] = $entity;
        }
    }
}
