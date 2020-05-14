<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\SyncEvent;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Salesforce\Transformer\Util\AssociationCache;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

class TransformAssociations implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var AssociationCache $cache */
    protected $cache;
    /** @var ManagerRegistry */
    protected $registry;
    /** @var array */
    protected $temporaryTables = [];

    public function __construct(AssociationCache $cache, ManagerRegistry $registry)
    {
        $this->cache = $cache;
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

            /** @var FieldMetadata $fieldMeta */
            foreach ($classMeta->getActiveFieldMetadata() as $fieldMeta) {
                if ('association' === $fieldMeta->getTransformer()) {
                    if (!$record->sObject->getFields()[$fieldMeta->getField()]) {
                        $fieldMeta->setValueForEntity(
                            $record->entity,
                            null
                        );
                        continue;
                    }

                    if ($this->isSelfReferencedField($fieldMeta, $doctrineClassMeta)) {
                        $this->calculateUniqueIds($fieldMeta->getMetadata(), $doctrineClassMeta);
                        $this->generateTempTable($doctrineClassMeta, $entityManager);
                        $this->addEntry($record, $fieldMeta, $doctrineClassMeta);
                    }

                    $hit = $this->cache->fetch($record->sObject->getFields()[$fieldMeta->getField()]);
                    if (false !== $hit) {
                        $fieldMeta->setValueForEntity(
                            $record->entity,
                            $entityManager->getReference($hit[0], $hit[1])
                        );
                    }
                }
            }
        }
        $this->saveEntries();
    }

    /**
     * 1) turn the sfid stored in the temporary table into IDs.
     *     a) Figure out the relationship between salesforce SFID and the entity we are looking at
     * 2) update the field that we are working on on each self referencing entity to match the ID we stored in the temporary table a moment ago.
     */
    public function processSelfReferencedEntities(SyncEvent $event)
    {
        foreach ($this->temporaryTables as $temporaryTable) {
            $entityManager = $this->getEm($temporaryTable['className'], $this->registry);
            $connectMetaData = $event->getConnection()->getMetadataRegistry()->findMetadataByClass($temporaryTable['className']);
            $doctrineMetaData = $entityManager->getClassMetadata($temporaryTable['className']);

            $update1Statement = $entityManager->getConnection()->prepare($this->generateUpdateStatement1($temporaryTable, $doctrineMetaData, $connectMetaData));
            $update1Statement->execute();

            // For each field we have, we are going to need to run a separate update query for..
            $properties = $entityManager->getConnection()->fetchArray("SELECT distinct(property) FROM {$temporaryTable['temp_table']};");

            foreach ($properties as $property) {
                $update2Statement = $entityManager->getConnection()->prepare($this->generateUpdateStatement2($property, $temporaryTable, $doctrineMetaData, $connectMetaData));
                $update2Statement->execute();
            }
        }
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

    private function generateTempTable(ClassMetadata $classMetadata, EntityManager $em)
    {
        if (isset($this->temporaryTables[$classMetadata->getName()]['temp_table'])) {
            return;
        }

        $this->temporaryTables[$classMetadata->getName()]['temp_table'] = 'AEConnect_temp_'.count($this->temporaryTables);
        switch ($this->temporaryTables[$classMetadata->getName()]['uniqueIdType']) {
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

        $statement = $em->getConnection()->prepare('CREATE TEMPORARY TABLE '.$this->temporaryTables[$classMetadata->getName()]['temp_table']
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

    private function calculateUniqueIds(Metadata $metadata, ClassMetadata $classMetadata)
    {
        if (!isset($this->temporaryTables[$classMetadata->getName()])) {
            $this->temporaryTables[$classMetadata->getName()] = [
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
                    $this->temporaryTables[$classMetadata->getName()]['uniqueIdField'] = $fieldMetadatum->getField();
                    $this->temporaryTables[$classMetadata->getName()]['uniqueIdProperty'] = $classMetadata->getColumnName($fieldMetadatum->getProperty());
                    $this->temporaryTables[$classMetadata->getName()]['uniqueIdType'] = $classMetadata->getFieldMapping($fieldMetadatum->getProperty())['type'];
                }
                if ('Id' == $fieldMetadatum->getField()) {
                    $this->temporaryTables[$classMetadata->getName()]['sfidProperty'] = $fieldMetadatum->getProperty();
                }
            }
        }
    }

    /**
     * unique_id - A Unique ID whose column name is stored in the temporaryTable's uniqueIdProperty propery that belongs to the owning entity of the self referencing entity relationship
     * sfid -      the SFID for a field that is self referencing the other entity
     * field -     The column name for which the SFID must be insterted into as an ID.
     */
    private function addEntry(Record $record, FieldMetadata $fieldMetadata, ClassMetadata $classMetadata)
    {
        $this->temporaryTables[$classMetadata->getName()]['entries'][] = sprintf(
            "('%s', %s, '%s', '%s')",
            $record->sObject->getFields()['Id'],
            null === $record->sObject->getFields()[$this->temporaryTables[$classMetadata->getName()]['uniqueIdField']]
                ? 'null' :  "'".$record->sObject->getFields()[$this->temporaryTables[$classMetadata->getName()]['uniqueIdField']]."'",
            $record->sObject->getFields()[$fieldMetadata->getField()],
            $fieldMetadata->getProperty()
        );
    }

    private function saveEntries()
    {
        foreach ($this->temporaryTables as $temporaryTable) {
            if (count($temporaryTable['entries'])) {
                $em = $entityManager = $this->getEm($temporaryTable['className'], $this->registry);
                $statement = $em->getConnection()->prepare('INSERT INTO '.$temporaryTable['temp_table']
                    .' (id, unique_id, sfid, property) VALUES '.implode(', ', $temporaryTable['entries']));
                $temporaryTable['entries'] = [];
                $statement->execute();
            }
        }
    }

    /** In step one we are just going to preform the map of IDs to SFIDs of the field that we seek to fill out for our entities. */
    private function generateUpdateStatement1(array $temporaryTable, ClassMetadata $doctrineMetaData, Metadata $connectMetaData)
    {
        if ($doctrineMetaData->hasAssociation($connectMetaData->getIdFieldProperty())) {
            throw new \Exception('SPARE MY LIFE DUDE MISS WITH THIS LMAO I can write this out later if we ever need it.');
        //$association = $doctrineMetaData->getAssociationMapping($connectMetaData->getIdFieldProperty());
        } else {
            return sprintf(
                'UPDATE %s SET map = %s.id FROM %s WHERE %s.%s = %s.sfid;',
                $temporaryTable['temp_table'],
                $temporaryTable['real_table'],
                $temporaryTable['real_table'],
                $temporaryTable['real_table'],
                $doctrineMetaData->getColumnName($connectMetaData->getIdFieldProperty()),
                $temporaryTable['temp_table']
            );
        }
    }

    /**
     * And in this one, we are going to put the IDs we MAPped from statement1 into the real table under the given field.
     */
    private function generateUpdateStatement2(string $property, array $temporaryTable, ClassMetadata $doctrineMetaData, Metadata $connectMetaData)
    {
        // we can compute a sync on either the SFID or the Unique ID.  The unique ID will, mercifully, never be an association!
        // lets start with a base query and we can add to it as needed,
        // We want to update the real table's current field with the ID that we MAPPED in the last update.
        // There could be many FIELDS that we will be preforming this association mapping on, so we will filter down to just do 1 at a time.

        //Turns the property we have into the column name
        $association = $doctrineMetaData->getAssociationMapping($property);
        $field = $association['targetToSourceKeyColumns']['id'];

        $statement = sprintf(
            "UPDATE %s SET %s = %s.map FROM %s WHERE %s.property = '%s' AND (%s);",
            $temporaryTable['real_table'],
            $field,
            $temporaryTable['temp_table'],
            $temporaryTable['temp_table'],
            $temporaryTable['temp_table'],
            $property,
            '%s'
        );

        //Now we need to figure out how we can choose from the temporary tables matches on the real table, and we have 2
        // methods a user may have employed.
        $wheres = [];
        // First and easiest is a unique ID field, should we have gotten one.  Lets mix that in currently.
        if ('' !== $temporaryTable['uniqueIdProperty']) {
            $wheres[] = sprintf(
                '(%s.unique_id IS NOT NULL AND %s.%s = %s.unique_id)',
                $temporaryTable['temp_table'],
                $temporaryTable['real_table'],
                $temporaryTable['uniqueIdProperty'],
                $temporaryTable['temp_table']
            );
        }

        // And now for the more intricate SFID, if that was included.
        if ($temporaryTable['sfidProperty']) {
            if ($doctrineMetaData->hasAssociation($temporaryTable['sfidProperty'])) {
                throw new \Exception('2 weak 2 slow');
            //$association = $doctrineMetaData->getAssociationMapping($connectMetaData->getIdFieldProperty());
            } else {
                $wheres[] = sprintf(
                    '(%s.id  <> \'\' AND %s.%s = %s.id)',
                    $temporaryTable['temp_table'],
                    $temporaryTable['real_table'],
                    $temporaryTable['sfidProperty'],
                    $temporaryTable['temp_table']
                );
            }
        }
        // a match on either unique ID field or SFID will work here.
        return sprintf($statement, implode(' OR ', $wheres));
    }
}
