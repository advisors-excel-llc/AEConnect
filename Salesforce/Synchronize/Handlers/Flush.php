<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class Flush implements SyncTargetHandler
{
    use GetEmTrait;

    private $registry;
    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function process(SyncTargetEvent $event): void
    {
        $this->ensureEMOpen();
        // Persist new entities, if any
        foreach ($event->getTarget()->getNewEntities() as $entity) {
            $em = $this->getEm(get_class($entity), $this->registry);
            $em->persist($entity);
        }

        // Flush!
        $this->flushTransacitonal($event->getTarget());
    }

    private function ensureEMOpen()
    {
        foreach ($this->registry->getManagerNames() as $key => $emName) {
            $em = $this->registry->getManager($key);
            if (!$em->isOpen()) {
                $this->registry->resetManager($key);
            }
        }
    }

    private function flushTransacitonal(Target $target)
    {
        foreach ($this->registry->getManagers() as $manager) {
            /** @var $manager EntityManager */
            if (!$manager->isOpen()) {// Again, another check to make sure the manager is open
                continue;
            }
            try {
                $manager->transactional(
                    function (EntityManagerInterface $em) {
                        $em->flush();
                        $em->clear();
                    }
                );
            } catch (\Throwable $t) {
                // ensure all EMs are open after any error.
                $this->ensureEMOpen();
                // If a transaction fails, try to save entries one by one.
                // This really slows things down with 2 additional queries to the database needed on each updating entity..
                foreach ($target->records as $record) {
                    $this->flushOne($record);
                }
            }
        }
    }

    private function flushOne(Record $record)
    {
        if (!$record->entity) {
            return;
        }
        $manager = $this->getEm(get_class($record->entity), $this->registry);
        if ($record->needPersist()) {
            //If we try to merge a new entity we are going to walk face first into an entity not found error..
            $manager->persist($record->entity);
        } elseif ($record->needUpdate) {
            $manager->merge($record->entity);
        }

        try {
            $manager->flush();
        } catch (\Throwable $t) {
            // If an error occurs, note it
            $record->error = '#flush ' . $t->getMessage();
            // and ensure EM is open.
            $this->ensureEMOpen();
        }
    }
}
