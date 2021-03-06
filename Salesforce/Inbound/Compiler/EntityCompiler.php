<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EntityCompiler
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var EntityLocater
     */
    private $entityLocater;

    /**
     * @var FieldCompiler
     */
    private $fieldCompiler;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        ManagerRegistry $registry,
        EntityLocater $entityLocater,
        FieldCompiler $fieldCompiler,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->entityLocater     = $entityLocater;
        $this->fieldCompiler     = $fieldCompiler;
        $this->validator         = $validator;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param SObject $object
     * @param string $connectionName
     * @param bool $validate
     * @param string $intent
     * @param string $deliveryMethod
     *
     * @return array
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function compile(SObject $object, string $connectionName = 'default', $validate = true, $intent = '', $deliveryMethod = ''): array
    {
        $connection = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            throw new \RuntimeException("Connection '$connectionName' could not be found.");
        }

        $entities = [];
        $metas    = $connection->getMetadataRegistry()->findMetadataBySObject($object);

        foreach ($metas as $metadata) {
            $class = $metadata->getClassName();
            $this->logger->debug('Entity Compiler Looking for '.$class);
            $manager = $this->registry->getManagerForClass($class);
            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($class);
            $entity        = $this->convertToEntity(
                $object,
                $metadata,
                $deliveryMethod
            );

            // Check if the entity is not meant for this connection, allow the entity to be created, given that validation passes
            $connectionProp = $metadata->getConnectionNameField();

            // IF we are in a Change Event, we would not have a full payload to create a full record.
            if (null === $entity && $deliveryMethod === 'Change Event') {
                $this->logger->debug('Change Event Entity not found, moving on to the next.');
                continue;
            } else if (null !== $connectionProp
                && null !== $connectionProp->getValueFromEntity($entity)
                && !$this->hasConnection(
                    $entity,
                    $connection,
                    $metadata
                )
            ) {
                $this->logger->debug(
                    "Entity {type} with Id {id} and meant for {conn}",
                    [
                        'type' => $class,
                        'id'   => $classMetadata->getFieldValue(
                            $entity,
                            $classMetadata->getSingleIdentifierFieldName()
                        ),
                        'conn' => $connection->getName(),
                    ]
                );
                continue;
            }

            $this->logger->debug('Attempting to Map the Fields to the Entity.');
            $this->mapFieldsToEntity($object, $entity, $metadata);

            try {
                $recordType = $metadata->getRecordType();
                // Check that the RecordType matches what the Entity allows, if not, move on to any other metadata
                // configs
                if (null !== $recordType
                    && null !== $object->RecordTypeId
                    && null !== ($recordTypeName = $metadata->getRecordTypeDeveloperName($object->RecordTypeId))
                    && $recordType->getValueFromEntity($entity) !== $recordTypeName
                ) {
                    $manager->detach($entity);
                    $this->logger->debug(
                        "The record type given, {given}, does not match that of the entity, {match}.",
                        [
                            'given' => $recordTypeName,
                            'match' => $recordType->getValueFromEntity($entity),
                        ]
                    );
                    continue;
                }

                $entityId = $classMetadata->getSingleIdReflectionProperty()->getValue($entity);

                // Validate against entity assertions to ensure that entity can be written to the database
                // Always validate if entity is new or if the validation flag is true
                if (null !== $entityId && $deliveryMethod === 'Change Event') {
                    $this->logger->debug('Change Event and Entity Found: ID = '.$entityId);
                    $this->validate($entity, $connection);
                    $entities[] = $entity;
                    break;
                } else if (null === $entityId || $validate) {
                    $this->validate($entity, $connection);
                }

                $entities[] = $entity;
            } catch (\RuntimeException $e) {
                $manager->detach($entity);
                $this->logger->notice('Runtime Exception for Compile. '.$e->getMessage());
            }
        }

        $this->logger->debug('Returning '.count($entities).' Entit'.(count($entities) === 1 ? 'y' : 'ies').'.');
        return $entities;
    }

    /**
     * @param $entity
     * @param ConnectionInterface $connection
     */
    private function validate($entity, ConnectionInterface $connection)
    {
        $groups = [
            'ae_connect.inbound',
            'ae_connect.inbound.'.$connection->getName(),
            'ae_connect_inbound', // Deprecated
            'ae_connect_inbound.'.$connection->getName(), // Deprecated
        ];

        if ($connection->isDefault() && 'default' !== $connection->getName()) {
            $groups[] = 'ae_connect_inbound.default';
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

            throw new \RuntimeException(
                "The entity does not meet the following validations:".PHP_EOL.$err
            );
        }
    }

    /**
     * @param $entity
     * @param ConnectionInterface $connection
     * @param Metadata $metadata
     *
     * @return bool
     */
    private function hasConnection($entity, ConnectionInterface $connection, Metadata $metadata): bool
    {
        $connectionProp = $metadata->getConnectionNameField();
        $conn           = $connectionProp->getValueFromEntity($entity);

        if (null === $conn) {
            return false;
        }

        if (is_string($conn)) {
            return $conn === $connection->getName();
        }

        if ($conn instanceof ConnectionEntityInterface) {
            return $conn->getName() === $connection->getName();
        }

        if (is_array($conn) || $conn instanceof \ArrayAccess) {
            foreach ($conn as $value) {
                if ($value instanceof ConnectionEntityInterface && $value->getName() === $connection->getName()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param SObject $object
     * @param Metadata $metadata
     * @param string $intent
     * @param string $deliveryMethod
     *
     * @return object
     */
    private function convertToEntity(SObject $object, Metadata $metadata, $intent = '', $deliveryMethod = '')
    {
        $class  = $metadata->getClassName();
        $entity = null;

        try {
            $entity = $this->entityLocater->locate($object, $metadata);
        } catch (\Exception $e) {
            $this->logger->debug(
                'No existing entity found for {type} with Salesforce Id of {id}.',
                [
                    'type' => $object->__SOBJECT_TYPE__,
                    'id'   => $object->Id,
                ]
            );
        }

        $connectionProp = $metadata->getConnectionNameField();

        // If the entity doesn't exist, and we are dealing with a Change Event, but not creating, return false
        if (null === $entity && $intent !== 'CREATED' && $deliveryMethod === 'Change Event') {
            $this->logger->debug('Entity is null for Change Event. Exiting.');
            return null;
        } else if (null === $entity) { // If the entity doesn't exist, create a new one
            $this->logger->debug('Entity is null, attempting to create it.');
            $entity = new $class();

            // If the entity supports a connection name, set it
            if (null !== $connectionProp) {
                $value = $this->fieldCompiler->compileInbound(
                    $metadata->getConnectionName(),
                    $object,
                    $metadata->getConnectionNameField(),
                    $entity
                );
                $connectionProp->setValueForEntity($entity, $value);
            }
        }

        return $entity;
    }

    /**
     * @param SObject $object
     * @param $entity
     * @param Metadata $metadata
     */
    private function mapFieldsToEntity(SObject $object, $entity, Metadata $metadata): void
    {
        // Apply the field values from the SObject to the Entity
        foreach ($object->getFields() as $field => $value) {
            if (null === ($fieldMetadata = $metadata->getMetadataForField($field))) {
                continue;
            }

            $newValue = $this->fieldCompiler->compileInbound($value, $object, $fieldMetadata, $entity);
            $fieldMetadata->setValueForEntity($entity, $newValue);
        }
    }
}
