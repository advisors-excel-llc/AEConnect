<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

class TransformSelfReferences implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var ManagerRegistry */
    protected $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getTarget()->records as $record) {
            if (!($record->needCreate || $record->needUpdate)) {
                // We aren't updating or creating this record.
                continue;
            }

            $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
            $entityManager = $this->getEm(get_class($record->entity), $this->registry);
            $doctrineClassMeta = $entityManager->getClassMetadata($classMeta->getClassName());

            foreach ($classMeta->getActiveFieldMetadata() as $fieldMeta) {
                if ($this->isSelfReferencedField($fieldMeta, $doctrineClassMeta)) {
                    $this->calculateUniqueIds($event->getTarget(), $fieldMeta->getMetadata(), $doctrineClassMeta);
                    $this->generateTempTable($event->getTarget(), $doctrineClassMeta, $entityManager);
                    $this->addEntry($event->getTarget(), $record, $fieldMeta, $doctrineClassMeta);
                }
            }
        }
        $this->saveEntries($event->getTarget());
    }

    private function isSelfReferencedField(FieldMetadata $fieldMetadata, ClassMetadata $classMetadata)
    {
        if ($classMetadata->hasAssociation($fieldMetadata->getProperty())) {
            $map = $classMetadata->getAssociationMapping($fieldMetadata->getProperty());
            if ($map['targetEntity'] === $map['sourceEntity']) {
                $nullable = @$map['joinColumns'][0]['nullable'];
                if (!$nullable) {
                    // Hopefully this will never happen since having a not-nullable self referenced field seems hard to pull off . . .
                    throw new \Exception(sprintf('#sync #configuration | The property %s.%s is not nullable and self-referencing.
                            Bulk sync has no way of ensuring the entities come in in the correct order to allow us to set this field when the entity is first created.
                            This property must be marked is nullable in your doctrine configuration to allow for us to create entities first and then set this field properly.', $classMetadata->getName(), $fieldMetadata->getProperty()));
                }

                return true;
            }
        }

        return false;
    }

    private function generateTempTable(Target $target, ClassMetadata $classMetadata, EntityManager $em)
    {
        if (isset($target->temporaryTables[$classMetadata->getName()]['temp_table'])) {
            return;
        }

        $target->temporaryTables[$classMetadata->getName()]['temp_table'] = 'AEConnect_temp_'.count($target->temporaryTables);
        switch ($target->temporaryTables[$classMetadata->getName()]['uniqueIdType']) {
            case 'uuid':
                $type = 'uuid';
                break;
            case 'bigint':
                $type = 'BIGINT';
                break;
            case 'int':
            case 'integer':
                $type = 'INTEGER';
                break;
            default:
                $type = 'VARCHAR(255)';
        }

        $statement = $em->getConnection()->prepare('CREATE TEMPORARY TABLE '.$target->temporaryTables[$classMetadata->getName()]['temp_table']
            ."(
                id VARCHAR(40),
                unique_id $type,
                sfid VARCHAR(40) NOT NULL,
                property VARCHAR(255) NOT NULL,
                map BIGINT
            )"
        );
        $statement->execute();
    }

    private function calculateUniqueIds(Target $target, Metadata $metadata, ClassMetadata $classMetadata)
    {
        if (!isset($target->temporaryTables[$classMetadata->getName()])) {
            $target->temporaryTables[$classMetadata->getName()] = [
                'real_table' => $classMetadata->getTableName(),
                'className' => $classMetadata->getName(),
                'uniqueIdField' => '',
                'uniqueIdProperty' => '',
                'uniqueIdType' => '',
                'sfidProperty' => '',
                'entries' => [],
            ];

            /** @var FieldMetadata $fieldMetadatum */
            foreach ($metadata->getActiveFieldMetadata() as $fieldMetadatum) {
                if ($fieldMetadatum->isIdentifier()) {
                    $target->temporaryTables[$classMetadata->getName()]['uniqueIdField'] = $fieldMetadatum->getField();
                    $target->temporaryTables[$classMetadata->getName()]['uniqueIdProperty'] = $classMetadata->getColumnName($fieldMetadatum->getProperty());
                    $target->temporaryTables[$classMetadata->getName()]['uniqueIdType'] = $classMetadata->getFieldMapping($fieldMetadatum->getProperty())['type'];
                }
                if ('Id' == $fieldMetadatum->getField()) {
                    $target->temporaryTables[$classMetadata->getName()]['sfidProperty'] = $fieldMetadatum->getProperty();
                }
            }
        }
    }

    /**
     * unique_id - A Unique ID whose column name is stored in the temporaryTable's uniqueIdProperty propery that belongs to the owning entity of the self referencing entity relationship
     * sfid -      the SFID for a field that is self referencing the other entity
     * field -     The column name for which the SFID must be insterted into as an ID.
     */
    private function addEntry(Target $target, Record $record, FieldMetadata $fieldMetadata, ClassMetadata $classMetadata)
    {
        $target->temporaryTables[$classMetadata->getName()]['entries'][] = sprintf(
            "('%s', %s, '%s', '%s')",
            $record->sObject->getFields()['Id'],
            null === $record->sObject->getFields()[$target->temporaryTables[$classMetadata->getName()]['uniqueIdField']]
                ? 'null' : "'".$record->sObject->getFields()[$target->temporaryTables[$classMetadata->getName()]['uniqueIdField']]."'",
            $record->sObject->getFields()[$fieldMetadata->getField()],
            $fieldMetadata->getProperty()
        );
    }

    private function saveEntries(Target $target)
    {
        foreach ($target->temporaryTables as $temporaryTable) {
            if (count($temporaryTable['entries'])) {
                $em = $entityManager = $this->getEm($temporaryTable['className'], $this->registry);
                $statement = $em->getConnection()->prepare('INSERT INTO '.$temporaryTable['temp_table']
                    .' (id, unique_id, sfid, property) VALUES '.implode(', ', $temporaryTable['entries']));
                $temporaryTable['entries'] = [];
                $statement->execute();
            }
        }
    }
}
