<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:22 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Compiler;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SObjectCompiler
{
    use LoggerAwareTrait;

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
        RegistryInterface $registry,
        Transformer $transformer,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager  = $connectionManager;
        $this->registry           = $registry;
        $this->transformer        = $transformer;
        $this->validator          = $validator;

        if (null !== $logger) {
            $this->setLogger($logger);
        }
    }

    /**
     * @param $entity
     * @param string $connectionName
     *
     * @return CompilerResult
     */
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

        $this->validate($entity, $connection);

        $sObject = new CompositeSObject($metadata->getSObjectType());

        $idProp      = $metadata->getIdFieldProperty();
        $sObject->Id = $classMetadata->getFieldValue($entity, $idProp);

        foreach ($metadata->getIdentifyingFields() as $prop => $field) {
            $sObject->$field = $classMetadata->getFieldValue($entity, $prop);
        }

        $intent = UnitOfWork::STATE_REMOVED === $uow->getEntityState($entity)
            ? CompilerResult::DELETE
            : (null === $sObject->Id ? CompilerResult::INSERT : CompilerResult::UPDATE);

        switch ($intent) {
            case CompilerResult::INSERT:
                $this->compileForInsert($entity, $metadata, $classMetadata, $sObject);
                break;
            case CompilerResult::UPDATE:
                $this->compileForUpdate($entity, $changeSet, $metadata, $classMetadata, $sObject);
                break;
            case CompilerResult::DELETE:
                $this->compileForDelete($entity, $metadata, $classMetadata, $sObject);
        }

        $refId = spl_object_hash($entity);

        return new CompilerResult($intent, $sObject, $metadata, $refId);
    }

    private function validate($entity, ConnectionInterface $connection)
    {
        $groups = ['ae_connect_outbound', 'ae_connect_outbound.'.$connection->getName()];

        if ($connection->isDefault() && 'default' !== $connection->getName()) {
            $groups[] = 'ae_connect_outbound.default';
        }

        $messages = $this->validator->validate(
            $entity,
            null,
            $groups
        );

        if (count($messages) > 0) {
            $err = '';
            foreach ($messages as $message) {
                $err .= $message.PHP_EOL;
            }

            if (null !== $this->logger) {
                $this->logger->alert(
                    "The entity does not meet the following validations:".PHP_EOL."{err}",
                    [
                        'err' => $err,
                    ]
                );
            }

            throw new \RuntimeException(
                "The entity does not meet the following validations:".PHP_EOL.$err
            );
        }
    }

    /**
     * @param $property
     * @param $value
     * @param $entity
     * @param Metadata $metadata
     * @param ClassMetadata $classMetadata
     *
     * @return mixed
     */
    private function compileProperty(
        $property,
        $value,
        $entity,
        Metadata $metadata,
        ClassMetadata $classMetadata
    ) {
        $payload = TransformerPayload::outbound();
        $payload->setValue($value)
                ->setPropertyName($property)
                ->setFieldName($metadata->getFieldByProperty($property))
                ->setEntity($entity)
                ->setMetadata($metadata)
                ->setClassMetadata($classMetadata)
        ;
        $this->transformer->transform($payload);

        return $payload->getValue();
    }

    /**
     * @param $entity
     * @param $classMetadata
     * @param $metadata
     * @param $sObject
     */
    private function compileForInsert(
        $entity,
        Metadata $metadata,
        ClassMetadata $classMetadata,
        CompositeSObject $sObject
    ): void {
        $fields = $metadata->getPropertyMap();

        foreach ($fields as $property => $field) {
            $fieldMetadata = $metadata->getMetadataForProperty($property);

            // Don't attempt to set values for fields that cannot be updated in Salesforce
            if (null === $fieldMetadata || !$fieldMetadata->describe()->isCreateable()) {
                continue;
            }

            $value = $metadata->getMetadataForProperty($property)->getValueFromEntity($entity);
            if (null !== $value) {
                $sObject->$field = $this->compileProperty(
                    $property,
                    $value,
                    $entity,
                    $metadata,
                    $classMetadata
                );
            }
        }
    }

    /**
     * @param $entity
     * @param $changeSet
     * @param $metadata
     * @param $classMetadata
     * @param $sObject
     */
    private function compileForUpdate(
        $entity,
        array $changeSet,
        Metadata $metadata,
        ClassMetadata $classMetadata,
        CompositeSObject $sObject
    ): void {
        $fields = $metadata->getPropertyMap();
        foreach ($fields as $property => $field) {
            if (array_key_exists($property, $changeSet)) {
                $fieldMetadata = $metadata->getMetadataForProperty($property);

                // Don't attempt to set values for fields that cannot be updated in Salesforce
                if (null === $fieldMetadata || !$fieldMetadata->describe()->isCreateable()) {
                    continue;
                }

                $value = $fieldMetadata->getValueFromEntity($entity);
                if (null !== $value) {
                    $sObject->$field = $this->compileProperty(
                        $property,
                        $value,
                        $entity,
                        $metadata,
                        $classMetadata
                    );
                }
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
