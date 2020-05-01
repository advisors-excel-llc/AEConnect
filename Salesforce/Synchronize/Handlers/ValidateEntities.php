<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\RecordTypeMetadata;
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
        $recordClasses = array_map(function (Record $record) { return get_class($record->entity); }, $event->getTarget()->getRecordsWithEntities());
        $this->order = array_merge(array_combine($recordClasses, array_fill(0, count($recordClasses), 0)), $this->order);
        arsort($this->order, SORT_NUMERIC);

        $recordsByClass = array_reduce(
            $event->getTarget()->getRecordsWithEntities(),
            function ($carry, Record $record) {
                $carry[get_class($record->entity)][] = $record;

                return $carry;
            },
            array_combine(array_keys($this->order), array_fill(0, count($this->order), []))
        );

        foreach ($recordsByClass as $records) {
            foreach ($records as $record) {
                if (isset($validated[spl_object_hash($record->sObject)])) {
                    $record->error .= 'sObject already validated to another subclass.';
                    $record->valid = false;
                    continue;
                }
                $err = $this->validate($record, $event->getConnection());
                if (true === $err) {
                    $record->valid = true;
                    $record->error = '';
                    $validated[spl_object_hash($record->sObject)] = true;
                    ++$this->order[get_class($record->entity)];
                } else {
                    $record->error .= $err;
                    $record->valid = false;
                }
            }
        }
    }

    /**
     * @param Record $record
     * @param Metadata $metadata
     * @return bool|string
     */
    private function checkRecordId(Record $record, Metadata $metadata)
    {
        /** @var RecordTypeMetadata $recordTypeMeta */
        $recordTypeMeta = $metadata->getRecordType();
        // We don't have a record type meta defined for this entity or we didn't query the record type,
        // so we assume this object is OK.
        if (!$recordTypeMeta || !isset($record->sObject->getFields()[$recordTypeMeta->getField()])) {
            return true;
        }
        $recordType = $metadata->getRecordTypeDeveloperName($record->sObject->getFields()[$recordTypeMeta->getField()]);
        if ($recordTypeMeta->getName() === $recordType) {
            return true;
        }

        return '#validation #RecordType Expected '.$recordTypeMeta->getName().' Actual '.$recordType;
    }


    /**
     * @param Record $record
     * @param ConnectionInterface $connection
     * @return bool|string
     */
    private function validate(Record $record, ConnectionInterface $connection)
    {
        $groups = [
            'ae_connect.inbound',
            'ae_connect.inbound.'.$connection->getName(),
        ];

        if ($connection->isDefault() && 'default' !== $connection->getName()) {
            $groups[] = 'ae_connect_inbound.default';
        }

        $messages = $this->validator->validate(
            $record->entity,
            null,
            $groups
        );

        if (count($messages) > 0) {
            $err = PHP_EOL.'#validation '.PHP_EOL;
            foreach ($messages as $message) {
                $err .= '#'.$message->getPropertyPath().' | '.$message->getMessage().PHP_EOL;
            }

            return $err;
        }

        return $this->checkRecordId($record, $connection->getMetadataRegistry()->findMetadataForEntity($record->entity));
    }
}
