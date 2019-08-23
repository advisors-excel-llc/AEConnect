<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 11:07 AM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SfidReset
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param ConnectionInterface $connection
     * @param array $types
     *
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function clearIds(ConnectionInterface $connection, array $types)
    {
        foreach ($types as $type) {
            foreach ($connection->getMetadataRegistry()->findMetadataBySObjectType($type) as $metadata) {
                $describeSObject = $metadata->getDescribe();
                // We only want to clear the Ids on objects that will be acted upon
                if (!$describeSObject->isQueryable()
                    || !$describeSObject->isCreateable() || !$describeSObject->isUpdateable()
                ) {
                    continue;
                }

                $class         = $metadata->getClassName();
                $fieldMetadata = $metadata->getMetadataForField('Id');

                // If there's no mapping to an Id, really can't do anything to clear it
                if (null == $fieldMetadata) {
                    continue;
                }

                $this->doClear($connection, $class, $fieldMetadata);
            }
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $class
     * @param FieldMetadata $fieldMetadata
     *
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function doClear(ConnectionInterface $connection, string $class, FieldMetadata $fieldMetadata): void
    {
        $manager = $this->registry->getManagerForClass($class);
        /** @var ClassMetadata $classMetadata */
        $classMetadata = $manager->getClassMetadata($class);
        $association   = null;
        $targetManager = null;

        if ($classMetadata->hasAssociation($fieldMetadata->getProperty())) {
            $association   = $classMetadata->getAssociationMapping($fieldMetadata->getProperty());
            $targetManager = $this->registry->getManagerForClass($association['targetEntity']);
        }

        $repo   = $manager->getRepository($class);
        $offset = 0;

        while (count(($entities = $repo->findBy([], null, 20, $offset))) > 0) {
            foreach ($entities as $entity) {
                $this->clearSfidOnEntity($connection, $fieldMetadata, $association, $entity, $manager, $targetManager);
            }

            $manager->clear($class);
            $offset += count($entities);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param FieldMetadata $fieldMetadata
     * @param $association
     * @param $entity
     * @param ObjectManager $manager
     * @param ObjectManager|null $targetManager
     */
    private function clearSfidOnEntity(
        ConnectionInterface $connection,
        FieldMetadata $fieldMetadata,
        $association,
        $entity,
        ObjectManager $manager,
        ?ObjectManager $targetManager = null
    ): void {
        if (null === $association) {
            $val = $fieldMetadata->getValueFromEntity($entity);
            if (is_string($val)) {
                $fieldMetadata->setValueForEntity($entity, null);
            }
        } elseif ($association['type'] & ClassMetadata::TO_ONE) {
            if (null !== $targetManager
                && ($val = $fieldMetadata->getValueFromEntity($entity))
                && $val instanceof SalesforceIdEntityInterface
            ) {
                $conn = $val->getConnection();
                if (($conn instanceof ConnectionEntityInterface
                        && $conn->getName() === $connection->getName())
                    || (is_string($conn) && $conn === $connection->getName())
                ) {
                    $targetManager->remove($val);
                    if ($targetManager !== $manager) {
                        $targetManager->flush();
                    }
                    $fieldMetadata->setValueForEntity($entity, null);
                }
            }
        } else {
            /** @var ArrayCollection|SalesforceIdEntityInterface[] $sfids */
            $sfids = $fieldMetadata->getValueFromEntity($entity);
            foreach ($sfids as $sfid) {
                $conn = $sfid->getConnection();
                if (($conn instanceof ConnectionEntityInterface
                        && $conn->getName() === $connection->getName())
                    || (is_string($conn) && $conn === $connection->getName())
                ) {
                    $sfids->removeElement($sfid);
                    $targetManager->remove($sfid);
                    if ($targetManager !== $manager) {
                        $targetManager->flush();
                    }
                }
            }

            $fieldMetadata->setValueForEntity($entity, $sfids);
        }

        $manager->flush();
    }
}
