<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\RecordTypeMetadata;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Compiler\ObjectCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateEntityWithSObject implements SyncTargetHandler
{
    /** @var FieldCompiler */
    private $fieldCompiler;
    /** @var ObjectCompiler */
    private $objectCompiler;
    private $validator;
    /** @var SerializerInterface $serializer */
    private $serializer;

    /**
     * CreateEntityWithSObject constructor.
     * @param FieldCompiler $fieldCompiler
     * @param ValidatorInterface $validator
     * @param SerializerInterface $serializer
     */
    public function __construct(FieldCompiler $fieldCompiler, ObjectCompiler $objectCompiler, ValidatorInterface $validator, SerializerInterface $serializer)
    {
        $this->fieldCompiler = $fieldCompiler;
        $this->validator = $validator;
        $this->serializer = $serializer;
        $this->objectCompiler = $objectCompiler;
    }

    public function process(SyncTargetEvent $event): void
    {
        $classMetas = $event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($event->getTarget()->name);
        foreach ($classMetas as $classMeta) {
            foreach ($event->getTarget()->records as $record) {
                if ($record->canCreateInDatabase()) {
                    try {
                        $entity = $this->objectCompiler->fastCompile($classMeta, $record->sObject);
                    } catch (RuntimeException $e) {
                        $record->error = '#serialization sObject to entity : ' . $e->getMessage();
                    }
                }

        }


        foreach ($event->getTarget()->records as $record) {
            if ($record->canCreateInDatabase()) {
                if (empty($classMetas)) {
                    $classMetas  = $event->getConnection()->getMetadataRegistry()->findMetadataBySObject($record->sObject);
                }
                //We won't be sure which meta data we are supposed to target until we've validated constructed classes,
                // so regardless of if validation is running or not, new creations always validate.
                // TODO : We can speed this step up by only compiling useful fields by looking at the $this->validation metadata
                //      TODO cont : for the current class before validating, and if it passes all validation only then fully compile.
                foreach ($classMetas as $classMeta) {
                    //Create a new entity
                    $class = $classMeta->getClassName();
                    $entity = new $class;
                    // Apply the field values from the SObject to the Entity
                    foreach ($record->sObject->getFields() as $field => $value) {
                        if (null === ($fieldMetadata = $classMeta->getMetadataForField($field))) {
                            continue;
                        }

                        try {
                            $newValue = $this->fieldCompiler->compileInbound(
                                $value,
                                $record->sObject,
                                $fieldMetadata,
                                $entity,
                                true
                            );
                            if (null !== $newValue) {
                                $fieldMetadata->setValueForEntity($entity, $newValue);
                            }
                        } catch (\Throwable $e) {
                            $record->error = $e->getMessage();
                            break;
                        }
                    }
                    $err = $this->validate($entity, $event->getConnection());
                    if ($err === true) {
                        $record->entity = $entity;
                        $record->needPersist = true;
                        $record->error = '';
                        break;
                    }
                    $record->error .= $err;
                }
            }
        }
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
                $err .= $message->getPropertyPath() . ' | ' . $message->getMessage() . PHP_EOL;
            }
            return $err;
        }
        return true;
    }
}
