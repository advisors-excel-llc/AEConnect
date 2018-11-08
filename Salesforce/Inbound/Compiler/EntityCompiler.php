<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\SObject;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\RegistryInterface;
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
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var Transformer
     */
    private $transformer;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        Transformer $transformer,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->registry          = $registry;
        $this->transformer       = $transformer;
        $this->validator         = $validator;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param SObject $object
     * @param string $connectionName
     *
     * @return array
     */
    public function compile(SObject $object, string $connectionName = 'default'): array
    {
        $connection = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            throw new \RuntimeException("Connection '$connectionName' could not be found.");
        }

        $entities = [];
        $metas    = $connection->getMetadataRegistry()->findMetadataBySObjectType($object->__SOBJECT_TYPE__);

        foreach ($metas as $metadata) {
            $class         = $metadata->getClassName();
            $manager       = $this->registry->getManagerForClass($class);
            $classMetadata = $manager->getClassMetadata($class);
            $identifiers   = $metadata->getIdentifyingFields();
            $criteria      = [];

            if (empty($identifiers) ||
                array_values(
                    array_intersect(
                        array_keys($object->getFields()),
                        array_values($identifiers)
                    )
                ) !== array_values($identifiers)) {
                $identifiers = [$metadata->getIdFieldProperty() => 'Id'];
            }

            foreach ($identifiers as $prop => $field) {
                $value = $object->$field;
                if (null !== $value && is_string($value) && strlen($value) > 0) {
                    if ($classMetadata->getTypeOfField($field) instanceof UuidType) {
                        $value = Uuid::fromString($value);
                    }
                    $criteria[$prop] = $value;
                }
            }

            if (empty($criteria) && strlen($object->Id) > 0) {
                $criteria[$metadata->getIdFieldProperty()] = $object->Id;
            } elseif (empty($criteria)) {
                continue;
            }

            $entity = $manager->getRepository($class)->findOneBy($criteria);
            $connectionProp = $metadata->getConnectionNameField();

            if (null !== $entity) {
                // Check if the entity is meant for this connection
                if (null !== $connectionProp
                    && null !== ($entityConnectionName = $connectionProp->getValueFromEntity($entity))
                    && $connection->getName() !== $entityConnectionName
                ) {
                    continue;
                }
            } else {
                $entity = new $class();

                // If the entity supports a connection name, set it
                if (null !== $connectionProp) {
                    $connectionProp->setValueForEntity($entity, $connection->getName());
                }
            }

            foreach ($object->getFields() as $field => $value) {
                $fieldMetadata = $metadata->getMetadataForField($field);
                if (null === $fieldMetadata) {
                    continue;
                }
                $payload = TransformerPayload::inbound()
                                             ->setClassMetadata($classMetadata)
                                             ->setEntity($object)
                                             ->setMetadata($metadata)
                                             ->setFieldName($field)
                                             ->setPropertyName($fieldMetadata->getProperty())
                                             ->setValue(is_string($value) && strlen($value) === 0 ? null : $value)
                ;

                $this->transformer->transform($payload);
                $newValue = $payload->getValue();

                if (null !== $newValue) {
                    $fieldMetadata->setValueForEntity($entity, $newValue);
                }
            }

            try {
                $recordType = $metadata->getRecordType();

                if (null !== $recordType
                    && null !== $object->RecordTypeId
                    && null !== ($recordTypeName = $metadata->getRecordTypeName($object->RecordTypeId))
                    && $recordType->getValueFromEntity($entity) !== $recordTypeName
                ) {
                    throw new \RuntimeException(
                        sprintf(
                            "The record type given, %s, does not match that of the entity, %s.",
                            $recordTypeName,
                            $recordType->getValueFromEntity($entity)
                        )
                    );
                }

                $this->validate($entity, $connection);

                $entities[] = $entity;
            } catch (\RuntimeException $e) {
                $manager->detach($entity);

                $this->logger->alert($e->getMessage());
                $this->logger->debug($e->getTraceAsString());
            }
        }

        return $entities;
    }

    /**
     * @param $entity
     * @param ConnectionInterface $connection
     */
    private function validate($entity, ConnectionInterface $connection)
    {
        $groups = ['ae_connect_inbound', 'ae_connect_inbound.'.$connection->getName()];

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
}
