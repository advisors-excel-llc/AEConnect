<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/18/18
 * Time: 2:39 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Queue;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Compiler\FieldCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionResponse;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeResponse;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\Collection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;

class OutboundQueue implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CACHE_ID_MESSAGES = '__sobject_messages';

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var FieldCompiler
     */
    private $fieldCompiler;

    /**
     * @var array
     */
    private $messages = [];

    public function __construct(
        CacheProvider $cache,
        ConnectionManagerInterface $connectionManager,
        ManagerRegistry $registry,
        FieldCompiler $fieldCompiler,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->cache             = $cache;
        $this->registry          = $registry;
        $this->fieldCompiler     = $fieldCompiler;

        $this->messages = $this->cache->fetch(self::CACHE_ID_MESSAGES) ?? [];

        if (!$this->messages) {
            $this->messages = [];
        }

        $this->setLogger($logger ?: new NullLogger());
    }

    public function add(CompilerResult $result)
    {
        $connectionName                                             = $result->getConnectionName();
        $this->messages[$connectionName][$result->getReferenceId()] = $result;
    }

    public function count(?string $connectionName = null): int
    {
        if (null === $connectionName) {
            $count = 0;
            foreach (array_keys($this->messages) as $name) {
                $count += count($this->messages[$name]);
            }

            return $count;
        }

        return array_key_exists($connectionName, $this->messages) ? 0 : count($this->messages[$connectionName]);
    }

    public function send(?string $connectionName = null)
    {
        $names = null === $connectionName ? array_keys($this->messages) : [$connectionName];

        foreach ($names as $name) {
            $connection = $this->connectionManager->getConnection($name);
            if (null !== $connection && $connection->isActive()) {
                $this->sendMessages($connection);
            }
        }
    }

    /**
     * @param ConnectionInterface $connection
     */
    private function sendMessages(ConnectionInterface $connection): void
    {
        $client = $connection->getRestClient()->getCompositeClient();

        if (!array_key_exists($connection->getName(), $this->messages)) {
            return;
        }

        $queue = RequestBuilder::build($this->messages[$connection->getName()]);

        try {
            $request = RequestBuilder::buildRequest(
                $queue[CompilerResult::INSERT],
                $queue[CompilerResult::UPDATE],
                $queue[CompilerResult::DELETE]
            );

            if (empty($request->getCompositeRequest())) {
                $this->logger->debug('No more messages in queue.');

                return;
            }

            $responses = $client->sendCompositeRequest($request);
            $classes   = [];

            self::handleResponses(
                $connection,
                $responses,
                $queue[CompilerResult::INSERT],
                CompilerResult::INSERT,
                $classes
            );
            self::handleResponses(
                $connection,
                $responses,
                $queue[CompilerResult::UPDATE],
                CompilerResult::UPDATE,
                $classes
            );
            self::handleResponses(
                $connection,
                $responses,
                $queue[CompilerResult::DELETE],
                CompilerResult::DELETE,
                $classes
            );

            foreach ($classes as $class) {
                $manager = $this->registry->getManagerForClass($class);
                $manager->clear($class);
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->logger->error(
                'An exception occurred while trying to send queue: {msg}',
                [
                    'msg' => $e->getMessage()
                ]
            );
            $this->logger->debug($e->getTraceAsString());
        } catch (\Exception $e) {
            $this->logger->error(
                'An exception occurred while trying to send queue: {msg}',
                [
                    'msg' => $e->getMessage()
                ]
            );
            $this->logger->debug($e->getTraceAsString());
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param CompositeResponse $response
     * @param array $queue
     * @param string $intent
     * @param array $classes
     */
    private function handleResponses(
        ConnectionInterface $connection,
        CompositeResponse $response,
        array $queue,
        string $intent,
        array &$classes
    ) {
        $payloads = &$this->messages[$connection->getName()];

        foreach ($queue as $refId => $requests) {
            $result = $response->findResultByReferenceId($refId);

            // Since we're filtering out empty requests, it's possible that we may be looking for a refId that
            // was mapped to an empty queue. In that case, the result would be null
            if (null === $result) {
                continue;
            }

            if (200 != $result->getHttpStatusCode()) {
                /** @var CompilerResult $item */
                foreach ($requests as $item) {
                    unset($payloads[$item->getReferenceId()]);
                }

                /** @var CollectionResponse[] $errors */
                $errors = $result->getBody();
                foreach ($errors as $error) {
                    $this->logger->error(
                        'AE_CONNECT error from SalesForce: ({code}) {msg}',
                        [
                            'code' => $error->getErrorCode(),
                            'msg'  => $error->getMessage(),
                        ]
                    );
                }
            } else {
                /** @var CollectionResponse[] $messages */
                $messages = $result->getBody();
                $refQ     = $queue[$refId];

                if ($refQ instanceof Collection) {
                    $refQ = $refQ->toArray();
                }

                $items = array_values($refQ);
                foreach ($messages as $i => $res) {
                    if (!array_key_exists($i, $items)) {
                        continue;
                    }
                    /** @var CompilerResult $item */
                    $item = $items[$i];
                    $ref  = $item->getReferenceId();

                    unset($payloads[$ref]);

                    if ($res->isSuccess() && CompilerResult::INSERT === $intent) {
                        $this->updateCreatedEntity($item, $res, $connection);
                        $class = $item->getClassName();
                        if (false === array_search($class, $classes)) {
                            $classes[] = $class;
                        }
                    } elseif (!$res->isSuccess()) {
                        foreach ($res->getErrors() as $error) {
                            $this->logger->error(
                                'AE_CONNECT error from SalesForce: {type}|{intent}|{code}|{msg}',
                                [
                                    'type'   => $item->getSObject()->getType(),
                                    'intent' => $item->getIntent(),
                                    'code'   => $error->getStatusCode(),
                                    'msg'    => $error->getMessage(),
                                ]
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * @param CompilerResult $payload
     * @param CollectionResponse $result
     * @param ConnectionInterface $connection
     */
    private function updateCreatedEntity(
        CompilerResult $payload,
        CollectionResponse $result,
        ConnectionInterface $connection
    ): void {
        $metadata = $connection->getMetadataRegistry()->findMetadataByClass($payload->getClassName());
        if (null === $metadata->getIdFieldProperty()) {
            return;
        }

        $object            = $payload->getSobject();
        $id                = $result->getId();
        $idMap             = [];
        $identifyingFields = $metadata->getIdentifyingFields();

        foreach ($identifyingFields as $prop => $idProp) {
            $idMap[$prop] = $object->$idProp;
        }

        $manager = $this->registry->getManagerForClass($metadata->getClassName());
        // Clear the manager to prevent unwanted associations from getting in
        $manager->clear();
        $repo    = $manager->getRepository($metadata->getClassName());
        $entity  = $repo->findOneBy($idMap);

        if (null !== $entity) {
            $fieldMetadata = $metadata->getMetadataForField('Id');
            $value         = $this->fieldCompiler->compileInbound($id, $object, $fieldMetadata, $entity);
            $fieldMetadata->setValueForEntity($entity, $value);
            $manager->flush();
        }
    }
}
