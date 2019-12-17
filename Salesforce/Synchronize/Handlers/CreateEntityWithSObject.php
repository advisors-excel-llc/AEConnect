<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Compiler\ObjectCompiler;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use JMS\Serializer\Exception\RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateEntityWithSObject implements SyncTargetHandler
{
    /** @var ObjectCompiler */
    private $objectCompiler;
    private $validator;

    /**
     * CreateEntityWithSObject constructor.
     * @param ObjectCompiler $objectCompiler
     * @param ValidatorInterface $validator
     */
    public function __construct(ObjectCompiler $objectCompiler, ValidatorInterface $validator)
    {
        $this->validator = $validator;
        $this->objectCompiler = $objectCompiler;
    }

    public function process(SyncTargetEvent $event): void
    {
        $classMetas = $event->getConnection()->getMetadataRegistry()->findMetadataBySObjectType($event->getTarget()->name);
        $slowRecords = [];
        //F A S T
        foreach ($event->getTarget()->records as $record) {
            if ($record->canCreateInDatabase()) {
                foreach ($classMetas as $classMeta) {
                    try {
                        $entity = $this->objectCompiler->fastCompile($classMeta, $record->sObject);
                        $err = $this->validate($entity, $event->getConnection());
                        if ($err === true) {
                            $record->entity = $entity;
                            $record->needPersist = true;
                            $record->error = '';
                            break;
                        } else {
                            $record->error .= $err;
                        }
                    } catch (RuntimeException $e) {
                        $record->warning = '#performance #serialization sObject to entity : '.$e->getMessage().
                            PHP_EOL.' will use transformers instead to achieve deserialization.';
                        $slowRecords[] = $record;
                        break;
                    } catch (\Throwable $e) {
                        $record->error = $e->getMessage();
                    }
                }
            }
        }

        //S L O W
        foreach ($slowRecords as $record) {
            if ($record->canCreateInDatabase()) {
                //We won't be sure which meta data we are supposed to target until we've validated constructed classes,
                // so regardless of if validation is running or not, new creations always validate.
                foreach ($classMetas as $classMeta) {
                    try {
                        $entity = $this->objectCompiler->slowCompile($classMeta, $record->sObject);
                        $err    = $this->validate($entity, $event->getConnection());
                        if ($err === true) {
                            $record->entity      = $entity;
                            $record->needPersist = true;
                            $record->error       = '';
                            break;
                        }
                        $record->error .= $err;
                    } catch (\Throwable $e) {
                        $record->error = $e->getMessage();
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
            $err = PHP_EOL . '#validation ' . PHP_EOL;
            foreach ($messages as $message) {
                $err .= '#' . $message->getPropertyPath() . ' | ' . $message->getMessage() . PHP_EOL;
            }
            return $err;
        }
        return true;
    }
}
