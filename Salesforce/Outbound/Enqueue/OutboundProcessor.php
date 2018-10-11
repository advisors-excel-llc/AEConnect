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
use AE\ConnectBundle\Salesforce\Outbound\MessagePayload;
use AE\ConnectBundle\Salesforce\Outbound\Queue\QueueProcessor;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Model\Rest\Composite\CollectionResponse;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Enqueue\Client\TopicSubscriberInterface;
use Enqueue\Consumption\Result;
use Interop\Queue\PsrContext;
use Interop\Queue\PsrMessage;
use Interop\Queue\PsrProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\ManagerRegistry;

class OutboundProcessor implements PsrProcessor, TopicSubscriberInterface
{
    public const CACHE_ID_MESSAGES  = '__sobject_messages';

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
     * @var string
     */
    private static $topic;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        CacheProvider $cache,
        ManagerRegistry $registry,
        string $semaphoreLifespan = '30 seconds',
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager = $connectionManager;
        $this->cache             = $cache;
        $this->registry          = $registry;
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
        $connectionName = $message->getProperty('connection');
        $intent         = $message->getProperty('intent');
        $created        = $message->getProperty('created', new \DateTime());
        $refId          = $message->getProperty('refId');

        if (null === $connectionName || $created > $this->semaphore) {
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

        /** @var MessagePayload $payload */
        $payload = $connection->getRestClient()->getSerializer()->deserialize(
            $message->getBody(),
            MessagePayload::class,
            'json'
        )
        ;

        if (!$message->isRedelivered()) {
            $sObject = $payload->getSobject();

            if (!array_key_exists($connectionName, $this->messages)) {
                $this->messages[$connectionName] = new ArrayCollection([$intent => new ItemizedCollection()]);
            } elseif (!array_key_exists($intent, $this->messages[$connectionName])) {
                $this->messages[$connectionName]->set($intent, new ArrayCollection());
            }

            /** @var ItemizedCollection $payloadCollection */
            $payloadCollection = $this->messages[$connectionName][$intent];
            $payloadCollection->set($refId, $payload, $sObject->getType());

            $this->semaphore = new \DateTime();
        }

        if ($this->shouldFlush($connection)) {
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

    private function shouldFlush(ConnectionInterface $connection): bool
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
     * @param MessagePayload $payload
     * @param CollectionResponse $result
     */
    private function updateCreatedEntity(MessagePayload $payload, CollectionResponse $result): void
    {
        $object = $payload->getSobject();
        $idProp = $payload->getMetadata()->getPropertyByField('Id');

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
        $client  = $connection->getRestClient()->getCompositeClient();
        $queue   = QueueProcessor::buildQueue(
            $this->messages[$connection->getName()][SalesforceConnector::INTENT_INSERT]->toArray(),
            $this->messages[$connection->getName()][SalesforceConnector::INTENT_UPDATE]->toArray(),
            $this->messages[$connection->getName()][SalesforceConnector::INTENT_DELETE]->toArray()
        );
        $builder = QueueProcessor::generateCompositeRequestBuilder($queue);

        try {
            $request   = $builder->build();
            $responses = $client->sendCompositeRequest($request);

            /**
             * @var string $refId
             * @var ItemizedCollection $requests
             */
            foreach ($queue->toArray() as $refId => $requests) {
                $result = $responses->findResultByReferenceId($refId);
                if (200 !== $result->getHttpStatusCode()) {
                    foreach (array_keys($requests->toArray()) as $refId) {
                        /** @var ItemizedCollection $types */
                        foreach ($this->messages[$connection->getName()] as $types) {
                            $types->remove($refId);
                            $this->rejected[] = $refId;
                        }
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
                    $payloads = $requests->toArray();
                    $refIds   = array_keys($payloads);
                    foreach ($messages as $i => $res) {
                        $refId = $refIds[$i];
                        if ($res->isSuccess()) {
                            $this->acked[] = $refId;
                        } else {
                            $this->rejected[] = $refId;
                        }

                        /** @var ItemizedCollection $types */
                        foreach ($this->messages[$connection->getName()] as $intent => $types) {
                            if ($types->containsKey($refId)) {
                                $types->remove($refId);

                                if ($res->isSuccess() && SalesforceConnector::INTENT_INSERT === $intent) {
                                    $this->updateCreatedEntity($payloads[$refId], $res);
                                }
                            }
                        }
                    }
                }
            }

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
}
