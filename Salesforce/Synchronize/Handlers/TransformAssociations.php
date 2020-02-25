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
                    $fieldMeta->setValueForEntity(
                        $record->entity,
                        $entityManager->getReference(get_class($record->entity), $this->cache->fetch($record->sObject->getFields()[$fieldMeta->getField()]))
                    );
                }
            }
        }
    }
}
