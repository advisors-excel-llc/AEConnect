<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 4:22 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Compiler;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
     * @var FieldCompiler
     */
    private $fieldCompiler;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        FieldCompiler $fieldCompiler,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->fieldCompiler     = $fieldCompiler;
        $this->validator         = $validator;

        $this->setLogger($logger ?: new NullLogger());
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

        // Check if the entity is meant for this connection
        $connectionProp = $metadata->getConnectionNameField();
        if (null !== $connectionProp
            && null !== ($entityConnectionName = $connectionProp->getValueFromEntity($entity))
            && ((is_string($entityConnectionName) && $connection->getName() !== $entityConnectionName)
                || ($entityConnectionName instanceof ConnectionEntityInterface
                    && $connection->getName() === $entityConnectionName->getName()
                )
            )
        ) {
            $cName = $entityConnectionName instanceof ConnectionEntityInterface
                ? $entityConnectionName->getName()
                : $entityConnectionName;
            throw new \RuntimeException("Entity is meant for $cName and not {$connection->getName()}");
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
        $idProp  = $metadata->getIdFieldProperty();

        if (null !== $idProp) {
            $sObject->Id = $this->fieldCompiler->compileOutbound(
                $classMetadata->getFieldValue($entity, $idProp),
                $entity,
                $metadata->getMetadataForProperty($idProp),
                $sObject
            );
        }

        foreach ($metadata->getIdentifyingFields() as $prop => $field) {
            $value = $classMetadata->getFieldValue($entity, $prop);
            if (null !== $value && null !== $field) {
                $fieldMetadata = $metadata->getMetadataForField($field);
                $sObject->$field = $this->fieldCompiler->compileOutbound($value, $entity, $fieldMetadata, $sObject);
            }
        }

        $intent = UnitOfWork::STATE_REMOVED === $uow->getEntityState($entity)
            ? CompilerResult::DELETE
            : (null === $sObject->Id || empty($changeSet) ? CompilerResult::INSERT : CompilerResult::UPDATE);

        switch ($intent) {
            case CompilerResult::INSERT:
                $this->compileForInsert($entity, $metadata, $sObject);
                if (null !== $sObject->Id) {
                    $intent = CompilerResult::UPDATE;
                }
                break;
            case CompilerResult::UPDATE:
                $this->compileForUpdate($entity, $changeSet, $metadata, $classMetadata, $sObject);
                break;
            case CompilerResult::DELETE:
                $this->compileForDelete($entity, $metadata, $classMetadata, $sObject);
        }

        $refId = spl_object_hash($entity);

        return new CompilerResult($intent, $sObject, $className, $refId, $connectionName);
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

            $this->logger->alert(
                "The entity does not meet the following validations:".PHP_EOL."{err}",
                [
                    'err' => $err,
                ]
            );

            throw new \RuntimeException(
                "The entity does not meet the following validations:".PHP_EOL.$err
            );
        }
    }

    /**
     * @param $entity
     * @param $metadata
     * @param $sObject
     */
    private function compileForInsert(
        $entity,
        Metadata $metadata,
        CompositeSObject $sObject
    ): void {
        $fields = $metadata->getPropertyMap();

        foreach ($fields as $property => $field) {
            $fieldMetadata = $metadata->getMetadataForField($field);

            // Don't attempt to set values for fields that cannot be updated in Salesforce
            if (null === $fieldMetadata
                || null === ($describe = $fieldMetadata->describe())
                || !$describe->isCreateable()
            ) {
                if (null === ($describe = $fieldMetadata->describe())
                    || $describe->isCreateable()
                ) {
                    $this->logger->warning(
                        "No metadata found for Property '{prop}' mapped to '{field}' for insert.",
                        [
                            'prop'  => $property,
                            'field' => $field,
                        ]
                    );
                }
                continue;
            }

            $value = $fieldMetadata->getValueFromEntity($entity);
            if (null !== $value) {
                $sObject->$field = $this->fieldCompiler->compileOutbound(
                    $value,
                    $entity,
                    $fieldMetadata,
                    $sObject
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
                $fieldMetadata = $metadata->getMetadataForField($field);

                // Don't attempt to set values for fields that cannot be updated in Salesforce
                if (null === $fieldMetadata
                    || null === ($describe = $fieldMetadata->describe())
                    || !$describe->isUpdateable()
                ) {
                    if (null === ($describe = $fieldMetadata->describe())
                        || $describe->isUpdateable()) {
                        $this->logger->warning(
                            "No metadata found for Property '{prop}' mapped to '{field}' for update.",
                            [
                                'prop'  => $property,
                                'field' => $field,
                            ]
                        );
                    }
                    continue;
                }

                $value = $fieldMetadata->getValueFromEntity($entity);
                if (null !== $value) {
                    $sObject->$field = $this->fieldCompiler->compileOutbound(
                        $value,
                        $entity,
                        $fieldMetadata,
                        $sObject
                    );
                }
            } elseif (ucwords($field) === 'Id'
                && null !== ($id = $classMetadata->getFieldValue($entity, $property))) {
                $sObject->Id = $this->fieldCompiler->compileOutbound(
                    $id,
                    $entity,
                    $metadata->getMetadataForField('Id')
                );
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
        $property = $metadata->getPropertyByField('Id');

        if (null === $property) {
            throw new \RuntimeException("Attempted to delete an entity without a Salesforce Id.");
        }

        $id = $classMetadata->getFieldValue($entity, $property);

        if (null === $id) {
            throw new \RuntimeException("Attempted to delete an entity without a Salesforce Id.");
        }

        $sObject->Id = $this->fieldCompiler->compileOutbound(
            $id,
            $entity,
            $metadata->getMetadataForField('Id'),
            $sObject
        );
    }
}
