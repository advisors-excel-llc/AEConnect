<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Doctrine\EntityLocater;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Salesforce\Transformer\Util\AssociationCache;
use AE\ConnectBundle\Util\GetEmTrait;
use Symfony\Bridge\Doctrine\RegistryInterface;

class CacheAssociationsForTransformation implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var AssociationCache $cache */
    protected $cache;

    /** @var RegistryInterface */
    protected $registry;

    /** @var EntityLocater */
    protected $locater;

    private $transformingFields = [];

    public function __construct(AssociationCache $cache, RegistryInterface $registry, EntityLocater $locater)
    {
        $this->cache = $cache;
        $this->registry = $registry;
        $this->locater = $locater;
    }

    public function process(SyncTargetEvent $event): void
    {
        $locate = [];

        //Accumulate a list of SFIDs we need to locate in the database.
        foreach ($event->getTarget()->records as $record) {
            if (!($record->needCreate || $record->needUpdate)) {
                // We aren't updating or creating this record.
                continue;
            }

            $classMeta = $event->getConnection()->getMetadataRegistry()->findMetadataForEntity($record->entity);
            $entityMeta = $this->getEm($classMeta->getClassName(), $this->registry)->getClassMetadata($classMeta->getClassName());
            $fields = $this->getTransformerFields($classMeta);

            foreach ($fields as $fieldMetadata) {
                $sfid = $record->sObject->getFields()[$fieldMetadata->getField()];
                if (!$sfid || $this->cache->contains($sfid)) {
                    // cache hit!
                    continue;
                }
                if (!$entityMeta->hasAssociation($fieldMetadata->getProperty())) {
                    throw new \Exception('#Bulk #Configuration The property ' . $fieldMetadata->getProperty() . ' on class ' . $classMeta->getClassName() .
                        ' is marked for transformation VIA the association transformer, but the property is not an association in Doctrine.');
                }
                $association = $entityMeta->getAssociationMapping($fieldMetadata->getProperty());
                $locate[$association['targetEntity']][] = $sfid;
            }
        }

        //And now we find the IDs of those SFIDs
        foreach ($locate as $class => $sfids) {
            $results = $this->locater->locateEntitiesBySFID($class, $sfids, $event->getConnection());
            $this->cache->save($results);
        }
    }

    /**
     * @param Metadata $classMeta
     * @return FieldMetadata[]
     */
    private function getTransformerFields(Metadata $classMeta)
    {
        if (isset($this->transformingFields[$classMeta->getClassName()])) {
            return $this->transformingFields[$classMeta->getClassName()];
        }

        $transformingField = [];
        foreach ($classMeta->getFieldMetadata() as $fieldMetadata) {
            if ($fieldMetadata->getTransformer() === 'association') {
                $transformingField[] = $fieldMetadata;
            }
        }
        $this->transformingFields[$classMeta->getClassName()] = $transformingField;
        return $transformingField;
    }
}
