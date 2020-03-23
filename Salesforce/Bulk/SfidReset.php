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
use Doctrine\Persistence\ObjectManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class SfidReset implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var SObjectTreeMaker
     */
    private $treeMaker;

    public function __construct(ManagerRegistry $registry, SObjectTreeMaker $treeMaker)
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
    public function doClear(ConnectionInterface $connection, string $class, FieldMetadata $fieldMetadata): void
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

        try {
            if (! $manager instanceof EntityManager) {
                throw new InvalidConfigurationException(
                    '#SfidReset -> #doClear | #performance  Selected manager is not an entity manager.'
                );
            }

            if (null === $association) {
                $this->clearSfidAsProperty($manager, $class, $fieldMetadata->getProperty());
            } else {
                $this->clearSfidAsAssociation($manager, $connection, $class, $association);
            }
        } catch (InvalidConfigurationException $e) {
            $this->logger->warning($e->getMessage() . '  Trying EM method instead of DQL method (This is several orders of magnitude slower)');
            while (count(($entities = $repo->findBy([], [$idField => 'ASC'], 20, $offset))) > 0) {
                foreach ($entities as $entity) {
                    $this->clearSfidOnEntity($connection, $fieldMetadata, $association, $entity, $manager);
                }
                $manager->clear($class);
                $offset += count($entities);
            }
            return;
        }
    }

    /**
     * Simplest method, use an executed query to update all entities of a particular type, setting a chosen property name to null.
     * @param EntityManager $om
     * @param string $className
     * @param string $propertyName
     */
    private function clearSfidAsProperty(EntityManager $em, string $className, string $propertyName)
    {
        $this->logger->info("#SfidReset -> #clearSfidAsPropertyOnEntity | #query  Clearing SFIDs with DQL : UPDATE $className e set e.$propertyName = NULL");
        $query = $em->createQuery("UPDATE $className e set e.$propertyName = NULL");
        $query->execute();
    }

    /**
     * IN this scenario, the user has an entity they want to clear the SFIDs on, and that entity has a relationship with a salesforce ID class.
     * In order to build the DQL for this, we need a few annotations to be set properly.
     * 1)  On the SFID entity, the salesforceId() annotation must have been set at class level to identify that entity as an entity which holds an SFID field and a Connection
     * 2) The connection property on the field must be annotated with the AECOnnect annotation connection()
     * 3) the connection property must have be annotated with doctrine settings, making it either a joined toOne table or string column.
     *
     * Given these three things are true, we can use DQL to delete out all salesforce ID entries with a query that says
     * 'Remove all salesforce rows from the database that match a specific connection name and has a relationship to a row within the table belonging to the entity we are clearing SFIDs for.'
     * @param EntityManager $em
     * @param ConnectionInterface $connection
     * @param string $className
     * @param array $association
     */
    private function clearSfidAsAssociation(EntityManager $em, ConnectionInterface $connection, string $className, array $association)
    {
        $targetMetadata = $em->getClassMetadata($association['targetEntity']);
        $targetAeConnectMetadata = $connection->getMetadataRegistry()->findMetadataByClass($association['targetEntity']);


        if (!$targetAeConnectMetadata) {
            throw new InvalidConfigurationException("#sfidReset -> #clearSfidAsOneToAssociation | #preformance  
            The target entity ({$association['targetEntity']}) for the sfid relationship on $className is not annotated with @SalesforceId for AE Connect.");
        }
        if (!$targetAeConnectMetadata->getConnectionNameField()) {
            throw new InvalidConfigurationException("#sfidReset -> #clearSfidAsOneToAssociation | #preformance  
            The target entity ({$association['targetEntity']}) does not have a @connection annotation on any property.");
        }

        $connectionJoin = '';
        $connectionAndWhere = '';
        $connectionPropertyName = $targetAeConnectMetadata->getConnectionNameField()->getProperty();
        $connectionName = $connection->getName();

        if ($targetMetadata->hasField($targetAeConnectMetadata->getConnectionNameField()->getProperty())) {
            $connectionAndWhere = "e.$connectionPropertyName = '$connectionName'";
        } elseif ($targetMetadata->hasAssociation($targetAeConnectMetadata->getConnectionNameField()->getProperty())) {
            $connectionJoin = "LEFT JOIN e.$connectionPropertyName connection";
            $connectionAndWhere = "connection.name = '$connectionPropertyName'";
        } else {
            throw new InvalidConfigurationException("#sfidReset -> #clearSfidAsOneToAssociation | #preformance  
            The target entity ({$association['targetEntity']}) does not have a doctrine annotation for the connection property.");
        }
        $DQL = "DELETE FROM {$association['targetEntity']} e $connectionJoin WHERE $connectionAndWhere AND EXISTS(
                  SELECT source.id FROM $className source LEFT JOIN source.{$association['fieldName']} target WHERE target.id = e.id
              )";

        $this->logger->info("#SfidReset -> #clearSfidAsOneToAssociation | #query  Clearing SFIDs with DQL : $DQL");

        $query = $em->createQuery($DQL);
        $query->execute();
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
