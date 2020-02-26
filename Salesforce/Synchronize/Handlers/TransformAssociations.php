<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Salesforce\Transformer\Util\AssociationCache;
use AE\ConnectBundle\Util\GetEmTrait;
use Symfony\Bridge\Doctrine\RegistryInterface;

class TransformAssociations implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var AssociationCache $cache */
    protected $cache;
    /** @var RegistryInterface  */
    protected $registry;

    public function __construct(AssociationCache $cache, RegistryInterface $registry)
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

            foreach ($classMeta->getFieldMetadata() as $fieldMeta) {
                if ($fieldMeta->getTransformer() === 'association' && $record->sObject->getFields()[$fieldMeta->getField()]) {
                    $hit = $this->cache->fetch($record->sObject->getFields()[$fieldMeta->getField()]);
                    // TODO : Here we can actually recover from this dreaded issue https://github.com/advisors-excel-llc/AEConnect/issues/213
                    if ($hit !== false) {
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
