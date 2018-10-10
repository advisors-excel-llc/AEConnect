<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 4:21 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound\Enqueue;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\MessagePayload;
use AE\ConnectBundle\Salesforce\Outbound\Queue\QueueProcessor;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Util\ItemizedCollection;
use AE\SalesforceRestSdk\Model\Rest\Composite\SubRequestResult;
use AE\SalesforceRestSdk\Model\Rest\CreateResponse;
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
    public const CACHE_ID_SEMAPHORE = '__sobject_semaphore';
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
        $this->semaphore         = $this->cache->contains(self::CACHE_ID_SEMAPHORE)
            ? $this->cache->fetch(self::CACHE_ID_SEMAPHORE)
            : null;

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

        $sObject = $payload->getSobject();

        if (!array_key_exists($connectionName, $this->messages)) {
            $this->messages[$connectionName] = new ArrayCollection([$intent => new ItemizedCollection()]);
        } elseif (!array_key_exists($intent, $this->messages[$connectionName])) {
            $this->messages[$connectionName]->set($intent, new ArrayCollection());
        }

        /** @var ItemizedCollection $payloadCollection */
        $payloadCollection = $this->messages[$connectionName][$intent];
        $payloadCollection->set($refId, $payload, $sObject->getType());

        if ($this->shouldFlush()) {
            $this->semaphore = new \DateTime();

            $client   = $connection->getRestClient()->getCompositeClient();
            $queue    = QueueProcessor::buildQueue($this->messages[$connectionName]);
            $requests = QueueProcessor::buildCompositeRequests($queue);

            try {
                foreach ($requests as $index => $request) {
                    $response = $client->sendCompositeRequest($request);
                    /**
                     * @var string $intent
                     * @var ItemizedCollection $types
                     */
                    foreach ($queue[$index] as $intent => $types) {
                        foreach ($types as $type => $objects) {
                            /**
                             * @var string $refId
                             * @var MessagePayload $payload
                             */
                            foreach ($objects as $refId => $payload) {
                                $result = $response->findResultByReferenceId($refId);
                                if (null !== $result) {
                                    $this->messages[$connectionName][$intent]->remove($refId, $type);

                                    if (300 > $result->getHttpStatusCode()) {
                                        $this->acked->add($refId);

                                        if (SalesforceConnector::INTENT_INSERT === $intent) {
                                            $this->updateCreatedEntity($payload, $result);
                                        }
                                    } else {
                                        $this->rejected->add($refId);

                                        // TODO: log error
                                    }
                                }
                            }
                        }
                    }
                }

                $this->cache->save(self::CACHE_ID_MESSAGES, $this->messages);
                $this->cache->save(self::CACHE_ID_SEMAPHORE, $this->semaphore);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                // TODO: log guzzle exception
            } catch (\Exception $e) {
                // TODO: log exception
            }
        } else {
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

    private function shouldFlush(): bool
    {
        if (null !== $this->semaphore) {
            $now  = new \DateTime();
            $then = (clone($this->semaphore))->add(\DateInterval::createFromDateString($this->semaphoreLifespan));

            return $now < $then;
        }
    }

    /**
     * @param MessagePayload $payload
     * @param SubRequestResult $result
     */
    private function updateCreatedEntity(MessagePayload $payload, SubRequestResult $result): void
    {
        $object = $payload->getSobject();
        $idProp = $payload->getMetadata()->getPropertyByField('Id');

        if (null === $idProp) {
            return;
        }

        $idMethod = "set".ucwords($idProp);

        /** @var CreateResponse $body */
        $body              = $result->getBody();
        $id                = $body->getId();
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
        }

        $manager->flush();
    }
}
