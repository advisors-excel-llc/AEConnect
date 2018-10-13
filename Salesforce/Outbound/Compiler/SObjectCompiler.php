<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:22 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Compiler;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Outbound\ReferenceIdGenerator;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SObjectCompiler
{
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var Transformer
     */
    private $transformer;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $managerRegistry,
        Transformer $transformer,
        ValidatorInterface $validator
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $managerRegistry;
        $this->transformer       = $transformer;
        $this->validator         = $validator;
    }

    public function compile($entity, string $connectionName = "default"): CompilerResult
    {
        $className = ClassUtils::getRealClass(get_class($entity));
        /** @var EntityManager $manager */
        $manager       = $this->registry->getManagerForClass($className);
        $classMetadata = $manager->getClassMetadata($className);
        $connection    = $this->connectionManager->getConnection($connectionName);
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);

        // This entity is not using this connection
        if (null === $metadata) {
            throw new \RuntimeException(
                "No Metadata for {$className} found relating to the {$connectionName} connection."
            );
        }

        $uow       = $manager->getUnitOfWork();
        $changeSet = $uow->getEntityChangeSet($entity);

        // If this is fired from a listener, the $changeSet will have values.
        // Otherwise, we need to compute the change set
        if (empty($changeSet)) {
            $uow->computeChangeSet($classMetadata, $entity);
            $changeSet = $uow->getEntityChangeSet($entity);
        }

        $this->validate($entity);

        $sObject = new CompositeSObject($metadata->getSObjectType());

        foreach ($metadata->getIdentifyingFields() as $prop => $field) {
            $sObject->$field = $classMetadata->getFieldValue($entity, $prop);
        }

        $refId  = ReferenceIdGenerator::create($entity, $metadata);
        $intent = UnitOfWork::STATE_REMOVED === $uow->getEntityState($entity)
            ? CompilerResult::DELETE
            : (null === $sObject->Id ? CompilerResult::INSERT : CompilerResult::UPDATE);

        switch ($intent) {
            case CompilerResult::INSERT:
                $this->compileForInsert($entity, $classMetadata, $metadata, $sObject, $refId);
                break;
            case CompilerResult::UPDATE:
                $this->compileForUpdate($entity, $changeSet, $metadata, $classMetadata, $sObject, $refId);
                break;
            case CompilerResult::DELETE:
                $this->compileForDelete($entity, $metadata, $classMetadata, $sObject);
        }

        return new CompilerResult($intent, $sObject, $metadata, $refId);
    }

    private function validate($entity)
    {
        // TODO: Validate Entity prior to mapping
    }

    /**
     * @param $property
     * @param $value
     * @param $entity
     * @param Metadata $metadata
     * @param ClassMetadata $classMetadata
     * @param $refId
     *
     * @return mixed
     */
    private function compileProperty(
        $property,
        $value,
        $entity,
        Metadata $metadata,
        ClassMetadata $classMetadata,
        $refId
    ) {
        $payload = TransformerPayload::outbound();
        $payload->setValue($value)
                ->setPropertyName($property)
                ->setEntity($entity)
                ->setMetadata($metadata)
                ->setClassMetadata($classMetadata)
                ->setRefId($refId)
        ;
        $this->transformer->transform($payload);

        return $payload->getValue();
    }

    /**
     * @param $entity
     * @param $classMetadata
     * @param $metadata
     * @param $refId
     * @param $sObject
     */
    private function compileForInsert(
        $entity,
        ClassMetadata $classMetadata,
        Metadata $metadata,
        CompositeSObject $sObject,
        $refId
    ): void {
        $fields = $metadata->getFieldMap();

        foreach ($fields as $property => $field) {
            $value           = $classMetadata->getFieldValue($entity, $property);
            $sObject->$field = $this->compileProperty(
                $property,
                $value,
                $entity,
                $metadata,
                $classMetadata,
                $refId
            );
        }
    }

    /**
     * @param $entity
     * @param $changeSet
     * @param $metadata
     * @param $classMetadata
     * @param $sObject
     * @param $refId
     */
    private function compileForUpdate(
        $entity,
        array $changeSet,
        Metadata $metadata,
        ClassMetadata $classMetadata,
        CompositeSObject $sObject,
        $refId
    ): void {
        $fields = $metadata->getFieldMap();
        foreach ($fields as $property => $field) {
            if (array_key_exists($property, $changeSet)) {
                $value           = $changeSet[$property][1];
                $sObject->$field = $this->compileProperty(
                    $property,
                    $value,
                    $entity,
                    $metadata,
                    $classMetadata,
                    $refId
                );
            } elseif (ucwords($field) === 'Id'
                && null !== ($id = $classMetadata->getFieldValue($entity, $property))) {
                $sObject->Id = $id;
            }
        }
    }

    /**
     * @param $entity
     * @param $metadata
     * @param $classMetadata
     * @param $sObject
     */
    private function compileForDelete(
        $entity,
        Metadata $metadata,
        ClassMetadata $classMetadata,
        CompositeSObject $sObject
    ): void {
        $field = $metadata->getPropertyByField('Id');

        if (null === $field) {
            throw new \RuntimeException("Attempted to delete an entity without a Salesforce Id.");
        }

        $id = $classMetadata->getFieldValue($entity, $field);

        if (null === $id) {
            throw new \RuntimeException("Attempted to delete an entity without a Salesforce Id.");
        }

        $sObject->Id = $id;
    }
}
