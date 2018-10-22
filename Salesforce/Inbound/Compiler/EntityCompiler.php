<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 4:16 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Transformer;
use AE\SalesforceRestSdk\Model\SObject;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
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

        if (null !== $logger) {
            $this->setLogger($logger);
        }
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
        $metas    = $connection->getMetadataRegistry()->findMetadataBySObjectType($object->Type);

        foreach ($metas as $metadata) {
            $class         = $metadata->getClassName();
            $manager       = $this->registry->getManagerForClass($class);
            $classMetadata = $manager->getClassMetadata($class);
            $identifiers   = $metadata->getIdentifyingFields();
            $criteria      = [];

            if (count(array_intersect($object->getFields(), $identifiers)) !== count($identifiers)) {
                $identifiers = [$metadata->getIdFieldProperty() => 'Id'];
            }

            foreach ($identifiers as $prop => $field) {
                if (null !== $object->$field) {
                    $criteria[$prop] = $object->$field;
                }
            }

            $entity = $manager->getRepository($class)->findOneBy($criteria) ?: new $class();

            foreach ($object->getFields() as $field => $value) {
                $fieldMetadata = $metadata->getMetadataForField($field);
                if (null === $fieldMetadata) {
                    continue;
                }
                $payload       = TransformerPayload::inbound()
                                                   ->setClassMetadata($classMetadata)
                                                   ->setEntity($object)
                                                   ->setMetadata($metadata)
                                                   ->setFieldName($field)
                                                   ->setPropertyName($fieldMetadata->getProperty())
                                                   ->setValue($value)
                ;

                $this->transformer->transform($payload);
                $fieldMetadata->setValueForEntity($entity, $payload->getValue());
            }

            try {
                $this->validate($entity);

                $entities[] = $entity;
            } catch (\RuntimeException $e) {
                if (null !== $this->logger) {
                    $this->logger->alert($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                }
            }
        }

        return $entities;
    }

    /**
     * @param $entity
     */
    private function validate($entity)
    {
        $messages = $this->validator->validate($entity, null, ['ae_connect_outbound']);
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