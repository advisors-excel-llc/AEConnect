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
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionResponse;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeResponse;
use Doctrine\Common\Cache\CacheProvider;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class OutboundQueue
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
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var array
     */
    private $messages = [];

    public function __construct(
        CacheProvider $cache,
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->cache             = $cache;
        $this->registry          = $registry;

        $this->messages = $this->cache->fetch(self::CACHE_ID_MESSAGES) ?? [];

        if (!$this->messages) {
            $this->messages = [];
        }

        if (null !== $logger) {
            $this->setLogger($logger);
        }
    }

    public function add(CompilerResult $result)
    {
        $connectionName                                             = $result->getMetadata()->getConnectionName();
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
            if (null !== $connection) {
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
        $queue  = RequestBuilder::build($this->messages[$connection->getName()]);

        try {
            $request   = RequestBuilder::buildRequest(
                $queue[CompilerResult::INSERT],
                $queue[CompilerResult::UPDATE],
                $queue[CompilerResult::DELETE]
            );
            $responses = $client->sendCompositeRequest($request);

            self::handleResponses($connection, $responses, $queue[CompilerResult::INSERT], CompilerResult::INSERT);
            self::handleResponses($connection, $responses, $queue[CompilerResult::UPDATE], CompilerResult::UPDATE);
            self::handleResponses($connection, $responses, $queue[CompilerResult::DELETE], CompilerResult::DELETE);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            if (null !== $this->logger) {
                $this->logger->critical(
                    'An exception occurred while trying to send queue.'
                );
                $this->logger->debug($e->getTraceAsString());
            }
        } catch (\Exception $e) {
            if (null !== $this->logger) {
                $this->logger->critical(
                    'An exception occurred while trying to send queue.'
                );
                $this->logger->debug($e->getTraceAsString());
            }
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param CompositeResponse $response
     * @param array $queue
     * @param string $intent
     */
    private function handleResponses(
        ConnectionInterface $connection,
        CompositeResponse $response,
        array $queue,
        string $intent
    ) {
        $payloads = &$this->messages[$connection->getName()];

        foreach ($queue as $refId => $requests) {
            $result = $response->findResultByReferenceId($refId);

            if (200 != $result->getHttpStatusCode()) {
                /** @var CompilerResult $item */
                foreach ($requests as $item) {
                    unset($payloads[$item->getReferenceId()]);
                }

                if (null !== $this->logger) {
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
                }
            } else {
                /** @var CollectionResponse[] $messages */
                $messages = $result->getBody();
                $items    = array_values($queue[$refId]->toArray());
                foreach ($messages as $i => $res) {
                    if (!array_key_exists($i, $items)) {
                        continue;
                    }
                    /** @var CompilerResult $item */
                    $item = $items[$i];
                    $ref  = $item->getReferenceId();

                    unset($payloads[$ref]);

                    if ($res->isSuccess() && CompilerResult::INSERT === $intent) {
                        $this->updateCreatedEntity($item, $res);
                    } elseif (!$res->isSuccess() && null !== $this->logger) {
                        foreach ($res->getErrors() as $error) {
                            $this->logger->error(
                                'AE_CONNECT error from SalesForce: ({code}) {msg}',
                                [
                                    'code' => $error->getStatusCode(),
                                    'msg'  => $error->getMessage(),
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
     */
    private function updateCreatedEntity(CompilerResult $payload, CollectionResponse $result): void
    {
        if (null === $payload->getMetadata()->getIdFieldProperty()) {
            return;
        }

        $object            = $payload->getSobject();
        $id                = $result->getId();
        $idMap             = [];
        $identifyingFields = $payload->getMetadata()->getIdentifyingFields();

        foreach ($identifyingFields as $prop => $idProp) {
            $idMap[$prop] = $object->$idProp;
        }

        $manager = $this->registry->getManagerForClass($payload->getMetadata()->getClassName());
        $repo    = $manager->getRepository($payload->getMetadata()->getClassName());
        $entity  = $repo->findOneBy($idMap);

        if (null !== $entity) {
            $payload->getMetadata()->getMetadataForField('Id')->setValueForEntity($entity, $id);
            $manager->flush();
        }
    }
}
