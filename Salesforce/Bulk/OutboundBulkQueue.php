<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/25/18
 * Time: 1:24 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\SalesforceRestSdk\Bulk\BatchInfo;
use AE\SalesforceRestSdk\Bulk\JobInfo;
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

    public function __construct(
        RegistryInterface $registry,
        EntityTreeMaker $treeMaker,
        SObjectCompiler $compiler,
        ?LoggerInterface $logger = null
    ) {
        $this->registry  = $registry;
        $this->treeMaker = $treeMaker;
        $this->compiler  = $compiler;

        if (null === $logger) {
            $this->setLogger(new NullLogger());
        } else {
            $this->setLogger($logger);
        }
    }

    public function process(
        ConnectionInterface $connection,
        array $types = [],
        int $batchSize = 2000,
        bool $updateExisting
        = false
    ) {
        $map              = empty($types) ? $this->treeMaker->buildFlatMap($connection) : $types;
        $metadataRegistry = $connection->getMetadataRegistry();

        // remove any classes from the map that aren't associated to any specific SOBject types provided (if any were)
        foreach ($types as $type) {
            $metadata = $metadataRegistry->findMetadataByClass($type);
            if (null === $metadata) {
                $index = array_search($type, $map);
                if (false !== $index) {
                    unset($map[$index]);
                }
            }
        }

        foreach ($map as $class) {
            $this->startJob($connection, $class, $batchSize, $updateExisting);
        }
    }

    private function startJob(ConnectionInterface $connection, string $class, int $batchSize, bool $updateExisting)
    {
        $metadata = $connection->getMetadataRegistry()->findMetadataByClass($class);

        if (null === $metadata
            || !$metadata->getDescribe()->isCreateable()
            || !$metadata->getDescribe()->isUpdateable()
            || $metadata->getIdentifiers()->count() === 0
        ) {
            return;
        }

        $client        = $connection->getBulkClient();
        $manager       = $this->registry->getManagerForClass($class);
        $classMetadata = $manager->getClassMetadata($class);
        $objectType    = $metadata->getSObjectType();
        /** @var FieldMetadata $externalIdFieldMetadata */
        $externalIdFieldMetadata = $metadata->getIdentifiers()->first();

        if (false === $externalIdFieldMetadata) {
            $externalIdFieldMetadata = $metadata->getMetadataForField('Id');
        }

        $job                     = $client->createJob(
            $objectType,
            JobInfo::UPSERT,
            JobInfo::TYPE_JSON,
            $externalIdFieldMetadata->getField()
        );
        $batches                 = [];
        $completed               = [];

        $this->logger->info(
            'Bulk Job (ID# {job}) is now open',
            [
                'job' => $job->getId(),
            ]
        );

        $offset = 0;
        $qb     = new QueryBuilder($manager);
        $qb->from($class, 'e')
           ->select('e')
           ->setFirstResult($offset)
           ->setMaxResults($batchSize)
        ;

        if (!$updateExisting) {
            $qb->andWhere($qb->expr()->isNull('e.'.$metadata->getIdFieldProperty()));
        }

        $pager = new Paginator($qb->getQuery(), false);

        while (count(($results = $pager->getIterator()->getArrayCopy())) > 0) {
            $objects   = [];
            $entityIds = [];

            foreach ($results as $result) {
                $entityIds[] = $classMetadata->getIdentifierValues($result);
                $objects[]   = $this->compiler->compile($result, $connection->getName())->getSObject();
            }

            $batch                    = $client->addBatch($job, $objects);
            $batches[$batch->getId()] = $entityIds;

            $this->logger->info(
                'Added Batch (ID# {batch}) to Job (ID# {job})',
                [
                    'batch' => $batch->getId(),
                    'job'   => $batch->getJobId() ?: $job->getId(),
                ]
            );

            $offset += count($results);
            $qb->setFirstResult($offset);
            $pager = new Paginator($qb->getQuery(), false);
        }

        while (count($batches) > 0) {
            foreach (array_keys($batches) as $batchId) {
                $batchStatus = $client->getBatchStatus($job->getId(), $batchId);

                if ($batchStatus->getState() === BatchInfo::STATE_COMPLETED) {
                    $this->logger->info(
                        'Batch (ID# {batch}) has completed.',
                        [
                            'batch' => $batchId,
                        ]
                    );

                    $completed[$batchId] = $batches[$batchId];
                    unset($batches[$batchId]);
                    $results = $client->getBatchResults($job->getId(), $batchId);

                    $this->processResults($results, $class, $metadata, $completed[$batchId]);
                } elseif ($batchStatus->getState() === "Failed") {
                    $this->logger->error(
                        "Batch (ID# {batch}) failed.",
                        [
                            'batch' => $batchId,
                        ]
                    );

                    unset($batches[$batchId]);

                    $results = $client->getBatchResults($job->getId(), $batchId);
                    $this->processResults($results, $class, $metadata, $completed[$batchId]);
                }
            }

            sleep(10);
        }

        $client->closeJob($job->getId());

        $this->logger->info('Job (ID# {job}) is now closed', ['job' => $job->getId()]);
    }

    private function processResults(array $results, string $class, Metadata $metadata, array &$entityIds)
    {
        $manager  = $this->registry->getManagerForClass($class);
        $idFields = array_splice($entityIds, 0, count($results));
        $count    = 0;
        $errored  = 0;

        foreach ($results as $i => $result) {
            if (true === $result['success']) {
                $fields = $idFields[$i];
                $entity = $manager->getRepository($class)->findOneBy($fields);
                $metadata->getMetadataForField('Id')->setValueForEntity($entity, $result['id']);
                ++$count;
            } else {
                foreach ($result['errors'] as $error) {
                    $this->logger->error(
                        'An error occurred when upserting bulk data for {type}: ({code}) {message}',
                        [
                            'type'    => $class,
                            'code'    => $error['errorCode'],
                            'message' => $error['message'],
                        ]
                    );
                }
            }
        }

        $manager->flush();

        $this->logger->info(
            'Updated {count} {type} entities. {errored} failed to upsert to Salesforce.',
            [
                'type'    => $class,
                'count'   => $count,
                'errored' => $errored,
            ]
        );
    }
}
