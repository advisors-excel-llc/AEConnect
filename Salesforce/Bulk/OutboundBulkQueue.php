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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

class OutboundBulkQueue
{
    use LoggerAwareTrait;

    /**
     * @var RegistryInterface
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
        RegistryInterface $registry,
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
     * @param bool $updateExisting
     *
     * @throws MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function process(
        ConnectionInterface $connection,
        array $types = [],
        bool $updateExisting = false
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

        $this->getTotals($map, $connection, $updateExisting);

        foreach ($map as $class) {
            $this->startJob($connection, $class, $updateExisting);
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $class
     * @param bool $updateExisting
     */
    private function startJob(ConnectionInterface $connection, string $class, bool $updateExisting)
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
            $qb = $this->createQueryBuilder($manager, $classMetadata, $connection, $offset, $updateExisting);
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

        $this->logger->debug('Synced {count} objects of {type} type', ['count' => $offset, 'type' => $class]);
    }

    /**
     * @param array $map
     * @param ConnectionInterface $connection
     * @param bool $updateExisting
     *
     * @throws MappingException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    private function getTotals(array $map, ConnectionInterface $connection, bool $updateExisting = false): void
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

            if (!$updateExisting && null !== ($idProp = $metadata->getIdFieldProperty())) {
                $this->appendQueryBuilderExtension($classMetadata, $connection, $idProp, $qb);
            }

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
     * @param bool $updateExisting
     *
     * @return QueryBuilder
     * @throws MappingException
     */
    private function createQueryBuilder(
        EntityManagerInterface $manager,
        ClassMetadata $classMetadata,
        ConnectionInterface $connection,
        int $offset = 0,
        bool $updateExisting = false
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

        if (!$updateExisting && null !== ($idProp = $metadata->getIdFieldProperty())) {
            $this->appendQueryBuilderExtension($classMetadata, $connection, $idProp, $qb);
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

    /**
     * @param ClassMetadata $classMetadata
     * @param ConnectionInterface $connection
     * @param $idProp
     * @param QueryBuilder $qb
     *
     * @throws MappingException
     */
    private function appendQueryBuilderExtension(
        ClassMetadata $classMetadata,
        ConnectionInterface $connection,
        $idProp,
        QueryBuilder $qb
    ): void {
        if ($classMetadata->hasField($idProp)) {
            $qb->andWhere($qb->expr()->isNull('e.'.$idProp));
        } elseif ($classMetadata->hasAssociation($idProp)) {
            $association = $classMetadata->getAssociationMapping($idProp);
            if ($association['type'] & ClassMetadataInfo::TO_ONE) {
                $qb->andWhere($qb->expr()->isNull('e.'.$idProp));
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

                $conn = null;

                if ($targetMetadata->hasField($connectionField)) {
                    $conn = $connection->getName();
                } elseif ($targetMetadata->hasAssociation($connectionField)) {
                    $connAssoc   = $targetMetadata->getAssociationMapping($connectionField);
                    $connClass   = $connAssoc['targetEntity'];
                    $connManager = $this->registry->getManagerForClass($connClass);
                    /** @var ClassMetadata $connMetadata */
                    $connMetadata = $connManager->getClassMetadata($connClass);
                    $connIdField  = $connMetadata->getSingleIdentifierFieldName();
                    $conn         = null;

                    if ($connAssoc['type'] & ClassMetadataInfo::TO_ONE) {
                        $repo = $connManager->getRepository($connClass);

                        if ($connMetadata->hasField('name')) {
                            $conn = $repo->findOneBy(
                                [
                                    'name' => $connection->getName(),
                                ]
                            );
                        } else {
                            foreach ($repo->findAll() as $item) {
                                if ($item instanceof ConnectionEntityInterface
                                    && $item->getName() === $connection->getName()
                                ) {
                                    $conn = $item;
                                    break;
                                }
                            }
                        }
                    }

                    if (null === $conn) {
                        throw new \RuntimeException(
                            sprintf(
                                "No %s with %s of %s found.",
                                $connClass,
                                $connectionField,
                                $connection->getName()
                            )
                        );
                    }

                    $conn = $connMetadata->getFieldValue($conn, $connIdField);
                }

                if (null !== $conn) {
                    $qb->leftJoin("e.$idProp", "s")
                       ->andWhere(
                           $qb->expr()->orX()
                              ->add($qb->expr()->neq("s.$connectionField", ":conn"))
                              ->add($qb->expr()->isNull("s.$connectionField"))
                       )
                       ->setParameter("conn", $conn)
                    ;
                }
            }
        }
    }
}
