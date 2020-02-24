<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ValidateEntities implements SyncTargetHandler
{
    /** @var ValidatorInterface */
    private $validator;

    public function __construct(ValidatorInterface $validator)
    {
        $this->validator = $validator;
    }

    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getTarget()->records as $record) {
            $err = $this->validate($record->entity, $event->getConnection());
            if ($err === true) {
                $record->valid = true;
                $record->error = '';
            } else {
                $record->error .= $err;
                $record->valid = false;
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
