<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Salesforce\Transformer\Util\AssociationCache;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\Persistence\ManagerRegistry;

class TransformAssociations implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var AssociationCache $cache */
    protected $cache;
    /** @var ManagerRegistry */
    protected $registry;

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
    }
}
