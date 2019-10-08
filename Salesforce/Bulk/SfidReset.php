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
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SfidReset implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var SObjectTreeMaker
     */
    private $treeMaker;

    public function __construct(RegistryInterface $registry, SObjectTreeMaker $treeMaker)
    {
        $this->registry = $registry;
        $this->treeMaker = $treeMaker;
        $this->logger = new NullLogger();
    }

    /**
     * @param ConnectionInterface $connection
     * @param array $types
     *
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function clearIds(ConnectionInterface $connection, array $types)
    {
        $map = $this->treeMaker->buildFlatMap($connection);

        if (!empty($types)) {
            $map = array_intersect($map, $types);
        }

        foreach ($map as $type) {
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
            $manager = $this->registry->getManagerForClass($association['targetEntity']) ?? $manager;
        }

        $idField = $classMetadata->getSingleIdentifierFieldName();
        $repo    = $manager->getRepository($class);
        $offset  = 0;

        //This is several orders of magnitude slower so don't go into this subflow.
        if (! $manager instanceof EntityManager) {
            $this->logger->warning('#SfidReset -> #doClear | #performance  Selected manager is not an entity manager and now we must use the object manager method for clearing SFIDs');
            while (count(($entities = $repo->findBy([], [$idField => 'ASC'], 20, $offset))) > 0) {
                foreach ($entities as $entity) {
                    $this->clearSfidOnEntity($connection, $fieldMetadata, $association, $entity, $manager);
                }
                $manager->clear($class);
                $offset += count($entities);
            }
            return;
        }

        switch(true) {
            case null === $association:
                $this->clearSfidAsPropertyOnEntity($manager, $class, $fieldMetadata->getProperty());
                break;
            case $association['type'] & ClassMetadata::TO_ONE:

        }


    }

    /**
     * Simplest method, use an executed query to update all entities of a particular type, setting a chosen property name to null.
     * @param EntityManager $om
     * @param string $className
     * @param string $propertyName
     */
    private function clearSfidAsPropertyOnEntity(EntityManager $em, string $className, string $propertyName)
    {
        $this->logger("#SfidReset -> #clearSfidAsPropertyOnEntity | #query  Clearing SFIDs with DQL : UPDATE $className e set e.$propertyName = NULL");
        $query = $em->createQuery("UPDATE $className e set e.$propertyName = NULL");
        $query->execute();
    }

    private function clearSfidAsOneToAssociation(EntityManager $em, ConnectionInterface $connection, string $className, array $association)
    {
        $connectionName = $connection->getName();
        $targetMetadata = $em->getClassMetadata($association['targetClass']);
        $connectionField = @$targetMetadata['reflFields']['connection'];


        $em->createQuery("DELETE FROM ${$association['targetClass']} e WHERE EXISTS(
                                  SELECT id FROM $className source LEFT JOIN source.${$association['fieldName']} target WHERE target.id = e.id
                              ) AND 
                                ");
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
        ObjectManager $manager
    ): void {
        if (null === $association) {
            $val = $fieldMetadata->getValueFromEntity($entity);
            if (is_string($val)) {
                $fieldMetadata->setValueForEntity($entity, null);
            }
        } elseif ($association['type'] & ClassMetadata::TO_ONE) {
            if (($val = $fieldMetadata->getValueFromEntity($entity))
                && $val instanceof SalesforceIdEntityInterface
            ) {
                $conn = $val->getConnection();
                if (($conn instanceof ConnectionEntityInterface
                        && $conn->getName() === $connection->getName())
                    || (is_string($conn) && $conn === $connection->getName())
                ) {
                    $manager->remove($val);
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
                    $manager->remove($sfid);
                }
            }

            $fieldMetadata->setValueForEntity($entity, $sfids);
        }

        $manager->flush();
    }
}
