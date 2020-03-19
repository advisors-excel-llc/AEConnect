<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\Handlers;

use AE\ConnectBundle\Salesforce\Synchronize\SyncTargetEvent;
use AE\ConnectBundle\Util\GetEmTrait;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Id\SequenceGenerator;
use Doctrine\Persistence\ManagerRegistry;

class ManipulateSequenceGenerator extends AbstractIdGenerator implements SyncTargetHandler
{
    use GetEmTrait;

    /** @var ManagerRegistry */
    private $registry;
    private $sequenceGeneratorDefinition;
    /** @var SequenceGenerator $wrappedGenerator */
    private $wrappedGenerator;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * A SequenceGenerator is used in Postgresql and Oracle DBs to query a sequence to figure out what the next available IDs are going to be for new rows on the table in the database.
     * the allocation size of a generator determines how many IDs to reserve each time a new entity is persisted through the DB.  Usually this is set to 1
     * But in these bulk operations where we may need many hundreds of thousands of Ids generated, we need a faster method than adding hundreds of thousands of sequence queries,
     * So here we are going to set the sequence generator's allocation size to match the curent batch size of new entities so it always takes 1 Sequence query to get all the Ids the
     * current batch will need.
     *
     * @param SyncTargetEvent $event
     */
    public function process(SyncTargetEvent $event): void
    {
        $newEntities = $event->getTarget()->getNewEntities();
        $allocationSize = count($newEntities);
        if ($allocationSize > 0) {
            $class = get_class(array_pop($newEntities));
            $em = $this->getEm($class, $this->registry);
            $metadata = $em->getClassMetadata($class);

            if ($metadata->isIdGeneratorSequence()) {
                $this->sequenceGeneratorDefinition = $metadata->sequenceGeneratorDefinition;
                $this->sequenceGeneratorDefinition['temporaryAllocationSize'] = max($allocationSize, $this->sequenceGeneratorDefinition['allocationSize']);
                $this->wrappedGenerator = new SequenceGenerator(
                    $this->sequenceGeneratorDefinition['sequenceName'],
                    $this->sequenceGeneratorDefinition['temporaryAllocationSize']
                );
                $metadata->idGenerator = $this;
            }
        }
    }

    /**
     * Generates an identifier for an entity.
     *
     * @param EntityManager $em
     * @param object|null $entity
     * @return mixed
     */
    public function generate(EntityManager $em, $entity)
    {
        if ($this->wrappedGenerator->getCurrentMaxValue() === null ||
            $this->wrappedGenerator->getNextValue() == $this->wrappedGenerator->getCurrentMaxValue()) {
            $nextId = $this->wrappedGenerator->generate($em, $entity);

            $increment = $this->sequenceGeneratorDefinition['temporaryAllocationSize'] - $this->sequenceGeneratorDefinition['allocationSize'];

            $incrementSQL = sprintf(
                'SELECT setval(\'%s\', %d)',
                $this->sequenceGeneratorDefinition['sequenceName'],
                $nextId + $increment
                );

            if ($increment !== 0) {
                $conn = $em->getConnection();
                $conn->query($incrementSQL)->execute();
            }

            return $nextId;
        } else {
            return $this->wrappedGenerator->generate($em, $entity);
        }
    }
}
