<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateEntityWithSObject implements SyncTargetHandler
{
    /** @var FieldCompiler  */
    private $fieldCompiler;
    private $validator;
    /**
     * CreateEntityWithSObject constructor.
     * @param FieldCompiler $fieldCompiler
     * @param ValidatorInterface $validator
     */
    public function __construct(FieldCompiler $fieldCompiler, ValidatorInterface $validator)
    {
        $this->fieldCompiler = $fieldCompiler;
        $this->validator = $validator;
    }

    public function process(SyncTargetEvent $event): void
    {
        $classMetas = [];
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

                        $newValue = $this->fieldCompiler->compileInbound(
                            $value,
                            $record->sObject,
                            $fieldMetadata,
                            $entity
                        );

                        if (null !== $newValue) {
                            $fieldMetadata->setValueForEntity($entity, $newValue);
                        }
                    }
                    if ($err = $this->validate($entity, $event->getConnection()) === true) {
                        $record->entity = $entity;
                        $record->needPersist = true;
                        break;
                    }
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
                $err .= $message.PHP_EOL;
            }
            return $err;
        }
        return true;
    }
}
