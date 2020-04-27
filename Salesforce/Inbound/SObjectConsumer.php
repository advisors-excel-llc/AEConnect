<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/21/18
 * Time: 3:08 PM.
 */

namespace AE\ConnectBundle\Salesforce\Inbound;

use AE\ConnectBundle\Connection\ConnectionsTrait;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use AE\ConnectBundle\Util\Exceptions\MemoryLimitException;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Message;
use AE\SalesforceRestSdk\Bayeux\Salesforce\Event;
use AE\SalesforceRestSdk\Bayeux\Salesforce\StreamingData;
use AE\SalesforceRestSdk\Model\SObject;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SObjectConsumer implements SalesforceConsumerInterface
{
    use ConnectionsTrait;
    use LoggerAwareTrait;

    private $consumeCount = 0;
    private $messageLimit = PHP_INT_MAX;
    private $memoryLimit = PHP_INT_MAX;

    /**
     * @var SalesforceConnector
     */
    private $connector;
    private $throughputCalculations = [
        'lastTime' => 0,
        'averageTime' => 0,
        'averageTimeBySObject' => [],
        'last100' => 0,
        'last100Queue' => 0,
        'throughput' => [
            'count' => 0,
            'time' => 0,
            'perMinute' => 0,
            'perHour' => 0,
        ],
    ];

    private $last100Queues = [];

    public function __construct(SalesforceConnector $connector, ?LoggerInterface $logger = null)
    {
        $this->connector = $connector;
        $this->setLogger($logger ?: new NullLogger());
    }

    public function channels(): array
    {
        return [
            'objects' => '*',
            'topics' => '*',
        ];
    }

    public function consume(ChannelInterface $channel, Message $message)
    {
        $data = $message->getData();
        $replayId = $data->getEvent()->getReplayId();

        $sObject = substr($this->getSfidFromData($data), 0, 3);

        $this->logger->debug(
            "#LISTENER RECEIVED #$sObject $replayId: Channel `{channel}` | {data}",
            [
                'channel' => $channel->getChannelId(),
                'data' => $message->getData()->getSobject() ?? $message->getData()->getPayload(),
            ]
        );

        $start = microtime(true);

        if (null !== $data) {
            if (null !== $data->getSobject()) {
                $this->consumeTopic($data->getSobject(), $data->getEvent());
            } elseif (null !== $data->getPayload() && is_array($data->getPayload())) {
                $this->consumeChangeEvent($data->getPayload());
            }
        }
        $stop = microtime(true);
        ++$this->consumeCount;
        $speed = $stop - $start;
        $this->logger->debug("#LISTENER COMPLETE #$sObject $replayId in $speed s");

        $this->throughputCalculations = $this->throughPut($this->throughputCalculations, $speed, $this->getSfidFromData($data));

        if (0 === $this->consumeCount % 50) {
            $this->logger->info(
                'THROUGHPUT CALCULATIONS {throughput}',
                ['throughput' => $this->throughputCalculations]
            );
        }

        $memory = memory_get_usage();
        if (($memory / (1024 * 1024)) > $this->memoryLimit) {
            $trace = debug_backtrace();
            throw new MemoryLimitException('Memory Limit exceeded after '.$this->consumeCount.' polls.  Function call stack is currently at '.count($trace), 0, $memory / (1024 * 1024));
        }

        if ($this->consumeCount > $this->messageLimit) {
            $trace = debug_backtrace();
            throw new MemoryLimitException('Count Limit exceeded after '.$memory / (1024 * 1024).' MiB were consumed.  Function call stack is currently at '.count($trace), 0, $memory / (1024 * 1024));
        }
    }

    private function throughPut(array $throughputCalculations, $time, ?string $sfid = null): array
    {
        //last time
        $throughputCalculations['lastTime'] = $time;

        //throughput
        ++$throughputCalculations['throughput']['count'];
        $throughputCalculations['throughput']['time'] += $time;
        $throughputCalculations['throughput']['perMinute'] =
            $throughputCalculations['throughput']['count'] / ($throughputCalculations['throughput']['time'] / 60);
        $throughputCalculations['throughput']['perHour'] =
            $throughputCalculations['throughput']['count'] / ($throughputCalculations['throughput']['time'] / 3600);

        //average
        $throughputCalculations['averageTime'] = $throughputCalculations['throughput']['count'] / $throughputCalculations['throughput']['time'];

        // last 100 average
        if (!isset($this->last100Queues[$throughputCalculations['last100Queue']])) {
            $this->last100Queues[$throughputCalculations['last100Queue']] = new \SplQueue();
        }
        /** @var \SplQueue $q */
        $q = $this->last100Queues[$throughputCalculations['last100Queue']];
        $throughputCalculations['last100'] += $time;
        $q->enqueue($time);
        if ($q->count() > 100) {
            $throughputCalculations['last100'] -= $q->dequeue();
        }

        //averageBysObject
        if ($sfid) {
            $sObject = substr($sfid, 0, 3);
            if (!isset($throughputCalculations['averageTimeBySObject'][$sObject])) {
                $throughputCalculations['averageTimeBySObject'][$sObject] = [
                    'lastTime' => 0,
                    'averageTime' => 0,
                    'last100' => 0,
                    'last100Queue' => count($this->last100Queues),
                    'throughput' => [
                        'count' => 0,
                        'time' => 0,
                        'perMinute' => 0,
                        'perHour' => 0,
                    ],
                ];
            }
            $throughputCalculations['averageTimeBySObject'][$sObject] = $this->throughPut($throughputCalculations['averageTimeBySObject'][$sObject], $time);
        }
        return $throughputCalculations;
    }

    private function getSfidFromData(?StreamingData $data): string
    {
        if (!$data) {
            return 'N/A';
        }
        if (null !== $data->getSobject()) {
            return $data->getSobject()->getId();
        } elseif (null !== $data->getPayload() && is_array($data->getPayload())) {
            return $data->getPayload()['ChangeEventHeader']['recordIds'][0];
        }
        return 'N/A';
    }

    public function setMemoryLimit(int $limit)
    {
        $this->memoryLimit = $limit;
    }

    public function setMessageLimit(int $limit)
    {
        $this->messageLimit = $limit;
    }

    public function getPriority(): ?int
    {
        return 0;
    }

    private function consumeChangeEvent(array $payload)
    {
        $changeEventHeader = $payload['ChangeEventHeader'];
        unset($payload['ChangeEventHeader']);

        $intent = $changeEventHeader['changeType'];
        $origin = $changeEventHeader['changeOrigin'];

        if (false !== ($pos = strpos($origin, ';'))) {
            $origin = substr($origin, $pos + 8); // ;client=$origin
        }

        switch ($intent) {
            case 'CREATE':
                $intent = SalesforceConsumerInterface::CREATED;
                break;
            case 'UPDATE':
                $intent = SalesforceConsumerInterface::UPDATED;
                break;
            case 'DELETE':
                $intent = SalesforceConsumerInterface::DELETED;
                break;
            case 'UNDELETE':
                $intent = SalesforceConsumerInterface::UNDELETED;
                break;
            default:
                return;
        }

        $sObject = new SObject(
            $payload + [
                '__SOBJECT_TYPE__' => $changeEventHeader['entityName'],
                'Id' => $changeEventHeader['recordIds'][0],
            ]
        );

        // Find Compound Fields in the object and assign their nexted values back to the SObject
        foreach ($payload as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $subField => $innerValue) {
                    if (null === $sObject->$subField) {
                        $sObject->$subField = $innerValue;
                    }
                }
            }
        }

        foreach ($this->connections as $connection) {
            if ($origin === $connection->getAppName() &&
                !in_array(
                    $changeEventHeader['entityName'],
                    $connection->getPermittedFilteredObjects()
                )
            ) {
                continue;
            }

            $this->connector->receive($sObject, $intent, $connection->getName());
        }
    }

    private function consumeTopic(SObject $object, Event $event)
    {
        if (null !== $object->__SOBJECT_TYPE__) {
            $intent = strtoupper($event->getType());

            foreach ($this->connections as $connection) {
                $this->connector->receive($object, $intent, $connection->getName());
            }
        }
    }
}
