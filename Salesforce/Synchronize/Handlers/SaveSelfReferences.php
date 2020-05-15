<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

class SaveSelfReferences implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var ManagerRegistry */
    protected $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 1) turn the sfid stored in the temporary table into IDs.
     *     a) Figure out the relationship between salesforce SFID and the entity we are looking at
     * 2) update the field that we are working on on each self referencing entity to match the ID we stored in the temporary table a moment ago.
     */
    public function process(SyncTargetEvent $event): void
    {
        foreach ($event->getTarget()->temporaryTables as $temporaryTable) {
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
