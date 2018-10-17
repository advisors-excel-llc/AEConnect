<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 4:21 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Enqueue;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Queue\RequestBuilder;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionResponse;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeResponse;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Enqueue\Client\TopicSubscriberInterface;
use Enqueue\Consumption\Result;
use Interop\Queue\PsrContext;
use Interop\Queue\PsrMessage;
use Interop\Queue\PsrProcessor;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\ManagerRegistry;

class OutboundProcessor implements PsrProcessor, TopicSubscriberInterface
{
    public const CACHE_ID_MESSAGES = '__sobject_messages';

    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var string
     */
    private $semaphoreLifespan;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /** @var \DateTime|null */
    private $semaphore;

    /**
     * @var array
     */
    private $messages = [];

    /**
     * @var ArrayCollection
     */
    private $acked;

    /**
     * @var ArrayCollection
     */
    private $rejected;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        CacheProvider $cache,
        ManagerRegistry $registry,
        SerializerInterface $serializer,
        string $semaphoreLifespan = '10 seconds',
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->cache             = $cache;
        $this->registry          = $registry;
        $this->serializer        = $serializer;
        $this->logger            = $logger;
        $this->semaphore         = new \DateTime();

        if ($this->cache->contains(self::CACHE_ID_MESSAGES)) {
            $this->messages = $this->cache->fetch(self::CACHE_ID_MESSAGES);
        }

        $this->semaphoreLifespan = $semaphoreLifespan;
        $this->acked             = new ArrayCollection();
        $this->rejected          = new ArrayCollection();
    }

    /**
     * @inheritDoc
     */
    public function process(PsrMessage $message, PsrContext $context): string
    {
        /** @var CompilerResult $payload */
        $payload        = $this->serializer->deserialize(
            $message->getBody(),
            CompilerResult::class,
            'json'
        );
        $refId          = $payload->getReferenceId();
        $connectionName = $payload->getMetadata()->getConnectionName();

        if (null === $connectionName || $message->getTimestamp() > $this->semaphore) {
            return Result::REJECT;
        }

        if ($this->acked->contains($refId)) {
            $this->acked->remove($refId);

            return Result::ACK;
        }

        if ($this->rejected->contains($refId)) {
            $this->rejected->remove($refId);

            return Result::REJECT;
        }

        $connection = $this->connectionManager->getConnection($connectionName);

        if (null === $connection) {
            return Result::REJECT;
        }

        if (!$message->isRedelivered()) {
            if (!array_key_exists($connectionName, $this->messages)) {
                $this->messages[$connectionName] = [];
            }

            $this->messages[$connectionName][$refId] = $payload;
            $this->semaphore                         = new \DateTime();
        }

        if ($this->shouldSend($connection)) {
            $this->send($connection);
            $this->semaphore = new \DateTime();
        } elseif (!$message->isRedelivered()) {
            // Hold onto these until we should flush
            $this->cache->save(self::CACHE_ID_MESSAGES, $this->messages);
        }

        return Result::REQUEUE;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedTopics()
    {
        return [self::$topic];
    }

    public static function setTopic(string $topic)
    {
        self::$topic = $topic;
    }

    private function shouldSend(ConnectionInterface $connection): bool
    {
        $now  = new \DateTime();
        $then = (clone($this->semaphore))->add(\DateInterval::createFromDateString($this->semaphoreLifespan));

        if ($now < $then) {
            return true;
        }

        $count = 0;

        foreach ($this->messages[$connection->getName()] as $types) {
            $count += count($types);
        }

        return 5000 <= $count;
    }

    /**
     * @param CompilerResult $payload
     * @param CollectionResponse $result
     */
    private function updateCreatedEntity(CompilerResult $payload, CollectionResponse $result): void
    {
        $object = $payload->getSobject();
        $idProp = $payload->getMetadata()->getIdFieldProperty();

        if (null === $idProp) {
            return;
        }

        $idMethod          = "set".ucwords($idProp);
        $id                = $result->getId();
        $idMap             = [];
        $identifyingFields = $payload->getMetadata()->getIdentifyingFields();

        foreach ($identifyingFields as $prop => $idProp) {
            $idMap[$prop] = $object->$idProp;
        }

        $manager = $this->registry->getManagerForClass(
            $payload->getMetadata()->getClassName()
        );
        $repo    = $manager->getRepository($payload->getMetadata()->getClassName());
        $entity  = $repo->findOneBy($idMap);

        if (null !== $entity) {
            $entity->$idMethod($id);
            $manager->flush();
        }
    }

    /**
     * @param ConnectionInterface $connection
     */
    private function send(ConnectionInterface $connection): void
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

            $this->cache->save(self::CACHE_ID_MESSAGES, $this->messages);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            if (null !== $this->logger) {
                $this->logger->critical(
                    'An exception occurred while trying to send queue:\n{msg}',
                    [
                        'msg' => $e->getTraceAsString(),
                    ]
                );
            }
        } catch (\Exception $e) {
            if (null !== $this->logger) {
                $this->logger->critical(
                    'An exception occurred while trying to send queue:\n{msg}',
                    [
                        'msg' => $e->getTraceAsString(),
                    ]
                );
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
                    $this->rejected->add($item->getReferenceId());
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
                foreach ($messages as $i => $res) {
                    /** @var CompilerResult $item */
                    $item  = $queue[$i];
                    $refId = $item->getReferenceId();

                    if ($res->isSuccess()) {
                        $this->acked->add($refId);
                    } else {
                        $this->rejected->add($refId);
                    }

                    unset($payloads[$refId]);

                    if ($res->isSuccess() && CompilerResult::INSERT === $intent) {
                        $this->updateCreatedEntity($item, $res);
                    }
                }
            }
        }
    }
}
