<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/31/18
 * Time: 12:03 PM
 */

namespace AE\ConnectBundle\Doctrine\Subscriber;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Orm\Mapping\ClassMetadata;
use Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;

class UuidSubscriber implements EventSubscriber
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    public function __construct(ConnectionManagerInterface $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    /**
     * @inheritDoc
     */
    public function getSubscribedEvents()
    {
        return [
            'prePersist',
            'preUpdate',
        ];
    }

    /**
     * @param LifecycleEventArgs $event
     *
     * @throws \Exception
     */
    public function prePersist(LifecycleEventArgs $event)
    {
        $em = $event->getObjectManager();
        if ($em instanceof EntityManager) {
            $this->populateUuids($event->getObject(), $em->getClassMetadata(get_class($event->getObject())));
        }
    }

    /**
     * @param LifecycleEventArgs $event
     *
     * @throws \Exception
     */
    public function preUpdate(LifecycleEventArgs $event)
    {
        $em = $event->getObjectManager();
        if ($em instanceof EntityManager) {
            $this->populateUuids($event->getObject(), $em->getClassMetadata(get_class($event->getObject())));
        }
    }

    /**
     * @param $entity
     * @param ClassMetadata $classMetadata
     *
     * @throws \Exception
     */
    private function populateUuids($entity, ClassMetadata $classMetadata)
    {
        foreach ($this->connectionManager->getConnections() as $connection) {
            $metadata = $connection->getMetadataRegistry()->findMetadataForEntity($entity);

            if (null === $metadata) {
                continue;
            }

            foreach ($metadata->getIdentifiers() as $fieldMetadata) {
                $field    = $fieldMetadata->getProperty();
                $type     = $classMetadata->getTypeOfField($field);
                $nullable = $classMetadata->isNullable($field);

                if ($classMetadata->isIdentifier($field) && $classMetadata->usesIdGenerator()) {
                    continue;
                }

                $value = $fieldMetadata->getValueFromEntity($entity);

                // No need to worry about it if nulls are A-OK
                if ($nullable && null === $value) {
                    continue;
                }

                if (is_string($type)) {
                    $type = Type::getType($type);
                }

                // We only want to worry about non-Uuid values
                if (($type instanceof UuidType || $type instanceof UuidBinaryType
                    || $type instanceof UuidBinaryOrderedTimeType)
                ) {
                    if (is_string($value) && strlen($value) === 0) {
                        $value = null;
                    }

                    if (null === $value) {
                        $fieldMetadata->setValueForEntity(
                            $entity,
                            $type instanceof UuidBinaryOrderedTimeType ? Uuid::uuid1() : Uuid::uuid4()
                        );
                    }
                }
            }
        }
    }
}
