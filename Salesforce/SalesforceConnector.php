<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 5:13 PM
 */

namespace AE\ConnectBundle\Salesforce;

use AE\ConnectBundle\Salesforce\Inbound\Compiler\EntityCompiler;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\CompilerResult;
use AE\ConnectBundle\Salesforce\Outbound\Compiler\SObjectCompiler;
use AE\ConnectBundle\Salesforce\Outbound\Enqueue\OutboundProcessor;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\Common\Util\ClassUtils;
use Enqueue\Client\Message;
use Enqueue\Client\ProducerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SalesforceConnector implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var SObjectCompiler
     */
    private $sObjectCompiler;

    /**
     * @var EntityCompiler
     */
    private $entityCompiler;

    /**
     * @var ProducerInterface
     */
    private $producer;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var bool
     */
    private $enabled = true;

    public function __construct(
        ProducerInterface $producer,
        SObjectCompiler $sObjectCompiler,
        EntityCompiler $entityCompiler,
        SerializerInterface $serializer,
        RegistryInterface $registry,
        ?LoggerInterface $logger = null
    ) {
        $this->producer        = $producer;
        $this->sObjectCompiler = $sObjectCompiler;
        $this->entityCompiler  = $entityCompiler;
        $this->serializer      = $serializer;
        $this->registry        = $registry;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param $entity
     * @param string $connectionName
     *
     * @return bool
     */
    public function send($entity, string $connectionName = 'default'): bool
    {
        if (!$this->enabled) {
            $this->logger->debug('Connector is disabled for {conn}', ['conn' => $connectionName]);

            return false;
        }

        try {
            $result = $this->sObjectCompiler->compile($entity, $connectionName);
        } catch (\RuntimeException $e) {
            $this->logger->warning($e->getMessage());

            return false;
        }

        $intent  = $result->getIntent();
        $sObject = $result->getSObject();

        if (CompilerResult::DELETE !== $intent) {
            // If there are no fields other than Id set, don't sync
            $fields = array_diff(array_keys($sObject->getFields()), ['Id']);
            if (empty($fields)) {
                $this->logger->debug(
                    'No fields for object {type} to insert or update for {conn}',
                    [
                        'type' => $sObject->getType(),
                        'conn' => $connectionName,
                    ]
                );

                return false;
            }
        }

        $message = new Message(
            $this->serializer->serialize($result, 'json')
        );
        $this->producer->sendEvent(OutboundProcessor::TOPIC, $message);

        return true;
    }

    /**
     * @param SObject|SObject[] $object
     * @param string $intent
     * @param string $connectionName
     *
     * @return bool
     */
    public function receive($object, string $intent, string $connectionName = 'default'): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!is_array($object)) {
            $object = [$object];
        }

        try {
            $entities = [];
            foreach ($object as $obj) {
                $entities = array_merge($entities, $this->entityCompiler->compile($obj, $connectionName));
            }
        } catch (\RuntimeException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());

            return false;
        }

        $classes = [];

        foreach ($entities as $entity) {
            $class   = ClassUtils::getClass($entity);
            $manager = $this->registry->getManagerForClass($class);

            if (false === array_search($class, $classes)) {
                $classes[] = $class;
            }

            switch ($intent) {
                case SalesforceConsumerInterface::CREATED:
                case SalesforceConsumerInterface::UPDATED:
                    $manager->merge($entity);
                    break;
                case SalesforceConsumerInterface::DELETED:
                    $manager->remove($entity);
                    break;
            }

            $this->logger->info('{intent} entity of type {type}', ['intent' => $intent, 'type' => $class]);
        }

        foreach ($classes as $class) {
            $manager = $this->registry->getManagerForClass($class);
            $manager->flush();
            $manager->clear($class);
        }

        return true;
    }

    /**
     * @return $this
     */
    public function enable()
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function disable()
    {
        $this->enabled = false;

        return $this;
    }
}
