<?php


namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;


use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Record;
use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Target;
use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class Flush implements SyncTargetHandler
{
    private $registry;
    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function process(SyncTargetEvent $event): void
    {
        $this->ensureEMOpen();
        // Persist new entities, if any
        foreach ($event->getTarget()->getNewEntities() as $entity) {
            $em = $this->registry->getEntityManagerForClass(get_class($entity));
            $em->persist($entity);
        }

        // Flush!
        $this->flushTransacitonal($event->getTarget());
    }

    private function ensureEMOpen()
    {
        foreach ($this->registry->getEntityManagerNames() as $key => $emName) {
            $em = $this->registry->getEntityManager($key);
            if (!$em->isOpen()) {
                $this->registry->resetEntityManager($key);
            }
        }
    }

    private function flushTransacitonal(Target $target)
    {
        foreach ($this->registry->getEntityManagers() as $manager) {
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
                // If a transaction fails, try to save entries one by one
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
        $manager = $this->registry->getEntityManagerForClass(get_class($record->entity));
        $manager->merge($record->entity);

        try {
            $manager->flush();
        } catch (\Throwable $t) {
            // If an error occurs, note it
            $record->error = $t->getMessage();
            // and ensure EM is open.
            $this->ensureEMOpen();
        }
    }
}
