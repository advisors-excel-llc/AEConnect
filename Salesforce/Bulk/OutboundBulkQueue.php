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

    public function __construct(
        RegistryInterface $registry,
        EntityTreeMaker $treeMaker,
        SObjectCompiler $compiler,
        OutboundQueue $outboundQueue,
        Reader $reader,
        ?LoggerInterface $logger = null
    ) {
        $this->registry      = $registry;
        $this->treeMaker     = $treeMaker;
        $this->compiler      = $compiler;
        $this->outboundQueue = $outboundQueue;
        $this->reader        = $reader;

        $this->setLogger($logger ?: new NullLogger());

        AnnotationRegistry::loadAnnotationClass(Connection::class);
    }

    public function process(
        ConnectionInterface $connection,
        array $types = [],
        bool $updateExisting
        = false
    ) {
        $map              = $this->treeMaker->buildFlatMap($connection);
        $metadataRegistry = $connection->getMetadataRegistry();

        if (!empty($types)) {
            $map = [];
            // remove any classes from the map that aren't associated to any specific SOBject types
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

        foreach ($map as $class) {
            $this->startJob($connection, $class, $updateExisting);
        }
    }

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

        $manager = $this->registry->getManagerForClass($class);
        /** @var ClassMetadata $classMetadata */
        $classMetadata = $manager->getClassMetadata($class);
        $offset        = 0;
        $qb            = new QueryBuilder($manager);
        $qb->from($class, 'e')
           ->select('e')
           ->setFirstResult($offset)
           ->setMaxResults(200)
        ;

        if (!$updateExisting && null !== ($idProp = $metadata->getIdFieldProperty())) {
            if ($classMetadata->hasField($idProp)) {
                $qb->andWhere($qb->expr()->isNull('e.'.$idProp));
            } elseif ($classMetadata->hasAssociation($idProp)) {
                try {
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
                } catch (MappingException $e) {
                    $this->logger->critical($e->getMessage());
                    $this->logger->debug($e->getTraceAsString());

                    return;
                }
            }
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

            if ($offset > 4800) {
                $this->logger->debug(
                    'AE_CONNECT: Sending {count} records to {conn}',
                    [
                        'count' => count($results),
                        'conn'  => $connection->getName(),
                    ]
                );
                $this->outboundQueue->send($connection->getName());
            }
        }

        $this->logger->debug(
            'AE_CONNECT: Sending {count} records to {conn}',
            [
                'count' => count($results),
                'conn'  => $connection->getName(),
            ]
        );
        // Send anything that hasn't already been sent
        $this->outboundQueue->send($connection->getName());

        $this->logger->debug('Synced {count} objects of {type} type', ['count' => $offset, 'type' => $class]);
    }
}
