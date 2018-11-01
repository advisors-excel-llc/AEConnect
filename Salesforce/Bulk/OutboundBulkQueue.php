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
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Queue\OutboundQueue;
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

    /**
     * @var OutboundQueue
     */
    private $outboundQueue;

    public function __construct(
        RegistryInterface $registry,
        EntityTreeMaker $treeMaker,
        SObjectCompiler $compiler,
        OutboundQueue $outboundQueue,
        ?LoggerInterface $logger = null
    ) {
        $this->registry      = $registry;
        $this->treeMaker     = $treeMaker;
        $this->compiler      = $compiler;
        $this->outboundQueue = $outboundQueue;

        if (null === $logger) {
            $this->setLogger(new NullLogger());
        } else {
            $this->setLogger($logger);
        }
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
        $offset  = 0;
        $qb      = new QueryBuilder($manager);
        $qb->from($class, 'e')
           ->select('e')
           ->setFirstResult($offset)
           ->setMaxResults(200)
        ;

        if (!$updateExisting) {
            $qb->andWhere($qb->expr()->isNull('e.'.$metadata->getIdFieldProperty()));
        }

        $pager = new Paginator($qb->getQuery());

        while (count(($results = $pager->getIterator()->getArrayCopy())) > 0) {
            foreach ($results as $result) {
                $object = $this->compiler->compile($result, $connection->getName());
                $object->setIntent(
                    null === $object->getSObject()->Id
                        ? CompilerResult::INSERT
                        : CompilerResult::UPDATE
                );
                $this->outboundQueue->add($object);
            }

            $offset += count($results);
            $qb->setFirstResult($offset);
            $pager = new Paginator($qb->getQuery(), false);

            if ($offset > 4800) {
                $this->outboundQueue->send($connection->getName());
            }
        }

        // Send anything that hasn't already been sent
        $this->outboundQueue->send($connection->getName());

        $this->logger->info('Synced {count} objects of {type} type', ['count' => $offset, 'type' => $class]);
    }
}
