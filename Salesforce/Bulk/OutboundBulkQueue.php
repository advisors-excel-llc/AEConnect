<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 1:24 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;

class OutboundBulkQueue
{
    use LoggerAwareTrait;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var EntityTreeMaker
     */
    private $treeMaker;

    /**
     * @var SObjectCompiler
     */
    private $compiler;

    /**
     * @var OutboundQueue
     */
    private $outboundQueue;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var BulkProgress
     */
    private $progress;

    /**
     * @var int
     */
    private $batchSize = 50;

    public function __construct(
        ManagerRegistry $registry,
        EntityTreeMaker $treeMaker,
        SObjectCompiler $compiler,
        OutboundQueue $outboundQueue,
        Reader $reader,
        BulkProgress $progress,
        int $batchSize = 50,
        ?LoggerInterface $logger = null
    ) {
        $this->registry      = $registry;
        $this->treeMaker     = $treeMaker;
        $this->compiler      = $compiler;
        $this->outboundQueue = $outboundQueue;
        $this->reader        = $reader;
        $this->progress      = $progress;

        $this->setLogger($logger ?: new NullLogger());

        AnnotationRegistry::loadAnnotationClass(Connection::class);
    }

    /**
     * @param ConnectionInterface $connection
     * @param array $types
     * @param bool $update
     * @param bool $create
     *
     * @throws MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function process(
        ConnectionInterface $connection,
        array $types = [],
        bool $update = false,
        bool $create = false
    ) {
        if (!$connection->isActive()) {
            throw new \RuntimeException("Connection '{$connection->getName()} is inactive.");
        }

        $map              = $this->treeMaker->buildFlatMap($connection);
        $metadataRegistry = $connection->getMetadataRegistry();

        if (!empty($types)) {
            $map = [];
            // remove any classes from the map that aren't associated to any specific SObject types
            // provided (if any were)
            foreach ($types as $type) {
                foreach ($metadataRegistry->findMetadataBySObjectType($type) as $metadata) {
                    $class = $metadata->getClassName();
                    $index = array_search($class, $map);
                    if (false === $index) {
                        $map[] = $class;
                    }
                }
            }
        }

        $this->getTotals($map, $connection, $update, $create);

        foreach ($map as $class) {
            $this->startJob($connection, $class, $update, $create);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $class
     * @param bool $update
     * @param bool $create
     */
    private function startJob(ConnectionInterface $connection, string $class, bool $update, bool $create)
    {
        $metadata = $connection->getMetadataRegistry()->findMetadataByClass($class);

        if (null === $metadata
            || !$metadata->getDescribe()->isCreateable()
            || !$metadata->getDescribe()->isUpdateable()
            || $metadata->getIdentifiers()->count() === 0
        ) {
            return;
        }

        /** @var EntityManagerInterface $manager */
        $manager = $this->registry->getManagerForClass($class);
        /** @var ClassMetadata $classMetadata */
        $classMetadata = $manager->getClassMetadata($class);
        $offset        = 0;

        try {
            $qb = $this->createQueryBuilder($manager, $classMetadata, $connection, $offset, $update, $create);
        } catch (MappingException $e) {
            $this->logger->critical($e->getMessage());
            $this->logger->debug($e->getTraceAsString());

            return;
        }

        $pager = new Paginator($qb->getQuery());

        while (count(($results = $pager->getIterator()->getArrayCopy())) > 0) {
            foreach ($results as $result) {
                try {
                    $object = $this->compiler->compile($result, $connection->getName());
                    $this->outboundQueue->add($object);
                    $this->logger->debug(
                        'AE_CONNECT: Added {type} object for {intent} to {conn}',
                        [
                            'type'   => $object->getSObject()->getType(),
                            'intent' => $object->getIntent(),
                            'conn'   => $connection->getName(),
                        ]
                    );
                } catch (\RuntimeException $e) {
                    $this->logger->warning($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());
                }
            }

            $offset += count($results);
            $qb->setFirstResult($offset);
            $pager = new Paginator($qb->getQuery(), false);

            if ($offset > 1000 - $this->batchSize) {
                $this->sendEntities($connection, $class, $results, $offset);
            }
        }

        $this->sendEntities($connection, $class, $results, $offset);

        $this->progress->setComplete($class);

        $this->logger->debug('Synced {count} objects of {type} type', ['count' => $offset, 'type' => $class]);
    }

    /**
     * @param array $map
     * @param ConnectionInterface $connection
     * @param bool $update
     * @param bool $create
     *
     * @throws MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function getTotals(array $map, ConnectionInterface $connection, bool $update, $create): void
    {
        $totals = [];

        foreach ($map as $class) {
            /** @var EntityManagerInterface $manager */
            $manager       = $this->registry->getManagerForClass($class);
            $classMetadata = $manager->getClassMetadata($class);
            $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($class);
            $id            = $classMetadata->getSingleIdentifierFieldName();
            $qb            = $manager->createQueryBuilder()
                                     ->select("Count(e.$id)")
                                     ->from($class, 'e')
            ;

            $orX = $qb->expr()->orX();

            if (!$update && $create && null !== ($idProp = $metadata->getIdFieldProperty())) {
                $this->appendQBAllowCreate($classMetadata, $connection, $idProp, $qb, $orX);
            }

            if ($update && !$create && null !== ($idProp = $metadata->getIdFieldProperty())) {
                $this->appendQBAllowUpdate($classMetadata, $connection, $idProp, $qb, $orX);
            }

            $qb->andWhere($orX);

            $count = $qb->getQuery()
                        ->getSingleScalarResult()
            ;

            $totals[$class] = (int)$count;
        }

        $this->progress->setProgress([]);
        $this->progress->setTotals($totals);
    }

    /**
     * @param EntityManagerInterface $manager
     * @param ClassMetadata $classMetadata
     * @param ConnectionInterface $connection
     * @param int $offset
     * @param bool $update
     * @param bool $create
     *
     * @return QueryBuilder
     * @throws MappingException
     */
    private function createQueryBuilder(
        EntityManagerInterface $manager,
        ClassMetadata $classMetadata,
        ConnectionInterface $connection,
        int $offset = 0,
        bool $update = false,
        bool $create = false
    ): QueryBuilder {
        $class    = $classMetadata->getName();
        $metadata = $connection->getMetadataRegistry()->findMetadataByClass($class);
        $idField  = $classMetadata->getSingleIdentifierFieldName();
        $qb       = new QueryBuilder($manager);
        $qb->from($class, 'e')
           ->select('e')
           ->setFirstResult($offset)
           ->setMaxResults($this->batchSize)
           ->orderBy("e.$idField")
        ;

        $orX = $qb->expr()->orX();

        if ($create && null !== ($idProp = $metadata->getIdFieldProperty())) {
            $this->appendQBAllowCreate($classMetadata, $connection, $idProp, $qb, $orX);
        }

        if ($update && null !== ($idProp = $metadata->getIdFieldProperty())) {
            $this->appendQBAllowUpdate($classMetadata, $connection, $idProp, $qb, $orX);
        }

        if ($orX->count()) {
            $qb->andWhere($orX);
        }

        return $qb;
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $class
     * @param $results
     * @param $offset
     */
    private function sendEntities(ConnectionInterface $connection, string $class, $results, $offset): void
    {
        $this->logger->debug(
            'AE_CONNECT: Sending {count} records to {conn}',
            [
                'count' => count($results),
                'conn'  => $connection->getName(),
            ]
        );
        // Send anything that hasn't already been sent
        $this->outboundQueue->send($connection->getName());

        $this->progress->updateProgress(
            $class,
            $this->progress->getProgress($class) + $offset
        );
    }

    private function appendQBAllowCreate(
        ClassMetadata $classMetadata,
        ConnectionInterface $connection,
        $idProp,
        QueryBuilder $qb,
        Orx $orX
    ): void
    {
        if ($classMetadata->hasField($idProp)) {
            $orX->add($qb->expr()->isNull('e.'.$idProp));
        } elseif ($classMetadata->hasAssociation($idProp)) {
            $association = $classMetadata->getAssociationMapping($idProp);
            if ($association['type'] & ClassMetadataInfo::TO_ONE) {
                $orX->add($qb->expr()->isNull('e.'.$idProp));
            } else {
                $targetClass   = $association['targetEntity'];
                /** @var EntityManager $targetManager */
                $targetManager = $this->registry->getManagerForClass($targetClass);
                /** @var ClassMetadata $targetMetadata */
                $targetMetadata = $targetManager->getClassMetadata($targetClass);

                // Find Connection Field
                $connectionField = 'connection';
                /** @var \ReflectionProperty $property */
                foreach ($targetMetadata->getReflectionProperties() as $property) {
                    foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                        if ($annotation instanceof Connection) {
                            $connectionField = $property->getName();
                            break;
                        }
                    }
                }

                if (!$connectionField) {
                    throw new \RuntimeException('No connection field was configured on the SFID entity.');
                }

                // This is a really exotic query that needs to be ran to get back a 1:1 relationship between results and entities.
                $subQuery = $targetManager->getRepository($classMetadata->getName())->createQueryBuilder('sub_e');
                $subQuery->leftJoin("sub_e.$idProp", "s")
                ->where("s.$connectionField = :conn1")
                ->andWhere("e.id = sub_e.id");

                $orX->add($qb->expr()->not($qb->expr()->exists($subQuery)));
                $qb->setParameter('conn1', $connection->getName());
            }
        }
    }

    private function appendQBAllowUpdate(
        ClassMetadata $classMetadata,
        ConnectionInterface $connection,
        $idProp,
        QueryBuilder $qb,
        Orx $orX
    ): void
    {
        if ($classMetadata->hasField($idProp)) {
            $orX->add("e.$idProp = :conn2");
        } elseif ($classMetadata->hasAssociation($idProp)) {
            $association = $classMetadata->getAssociationMapping($idProp);
            if ($association['type'] & ClassMetadataInfo::TO_ONE) {
                $orX->add("e.$idProp = :conn2");
            } else {
                $targetClass   = $association['targetEntity'];
                $targetManager = $this->registry->getManagerForClass($targetClass);
                /** @var ClassMetadata $targetMetadata */
                $targetMetadata = $targetManager->getClassMetadata($targetClass);

                // Find Connection Field
                $connectionField = 'connection';
                /** @var \ReflectionProperty $property */
                foreach ($targetMetadata->getReflectionProperties() as $property) {
                    foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                        if ($annotation instanceof Connection) {
                            $connectionField = $property->getName();
                            break;
                        }
                    }
                }

                if (! $connectionField) {
                    throw new \RuntimeException('No connection field was configured on the SFID entity.');
                }

                $qb->leftJoin("e.$idProp", "s");

                $orX->add($qb->expr()->eq("s.$connectionField", ":conn2"));

            }
        }
        $qb->setParameter('conn2', $connection->getName());
    }
}
