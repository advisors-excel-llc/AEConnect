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
use Doctrine\ORM\Event\PostFlushEventArgs;

class EntitySubscriber implements EventSubscriber
{
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
    }

    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate',
            'postRemove',
            'postFlush',
        ];
    }

    public function postPersist(LifecycleEventArgs $event)
    {
        $this->entities[] = $event->getEntity();
    }

    public function postUpdate(LifecycleEventArgs $event)
    {
        $this->entities[] = $event->getEntity();
    }

    public function postRemove(LifecycleEventArgs $event)
    {
        $this->entities[] = $event->getEntity();
    }

    public function postFlush(PostFlushEventArgs $event)
    {
        $connections = $this->connectionManager->getConnections();
        foreach ($connections as $connection) {
            foreach ($this->entities as $entity) {
                try {
                    $this->connector->send($entity, $connection->getName());
                } catch (\RuntimeException $e) {
                    // If the entity isn't able to be sent to Salesforce for any reason,
                    // a RuntimeException is thrown. We don't want that stopping our fun.
                }
            }
        }

        $this->entities = [];
    }
}
