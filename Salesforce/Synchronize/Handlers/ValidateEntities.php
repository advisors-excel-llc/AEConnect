<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ValidateEntities implements SyncTargetHandler
{
    /** @var ValidatorInterface */
    private $validator;
    private $order = [];

    public function __construct(ValidatorInterface $validator)
    {
        $this->validator = $validator;
    }

    public function process(SyncTargetEvent $event): void
    {
        $validated = [];
        //Lets try to validate classes in order of MOST OFTEN using this sweet trick.
        $recordClasses = array_map(function (Record $record) { return get_class($record->entity); }, $event->getTarget()->records);
        $this->order = array_merge(array_combine($recordClasses, array_fill(0, count($recordClasses), 0)), $this->order);
        arsort($this->order, SORT_NUMERIC);

        $recordsByClass = array_reduce(
            $event->getTarget()->records,
            function ($carry, Record $record) { $carry[get_class($record->entity)][] = $record; return $carry; },
            array_combine(array_keys($this->order), array_fill(0, count($this->order), []))
        );

        foreach($recordsByClass as $records) {
            foreach ($records as $record) {
                if (isset($validated[spl_object_hash($record->sObject)])) {
                    $record->error .= 'sObject already validated to another subclass.';
                    $record->valid = false;
                    continue;
                }
                $err = $this->validate($record->entity, $event->getConnection());
                if ($err === true) {
                    $record->valid                                = true;
                    $record->error                                = '';
                    $validated[spl_object_hash($record->sObject)] = true;
                    $this->order[get_class($record->entity)]++;
                } else {
                    $record->error .= $err;
                    $record->valid = false;
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
