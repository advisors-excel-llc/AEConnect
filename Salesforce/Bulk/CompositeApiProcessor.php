<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:51 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;

class CompositeApiProcessor
{
    /**
     * @var BulkPreprocessor
     */
    private $preProcessor;

    /**
     * @var SalesforceConnector
     */
    private $connector;

    /**
     * @var $batchSize
     */
    private $batchSize = 50;

    public function __construct(BulkPreprocessor $preprocessor, SalesforceConnector $connector, int $batchSize = 50)
    {
        $this->preProcessor = $preprocessor;
        $this->connector    = $connector;
        $this->batchSize    = $batchSize;
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $sObjectType
     * @param string $query
     * @param bool $updateEntity
     * @param bool $insertEntity
     *
     * @throws MappingException
     * @throws ORMException
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process(
        ConnectionInterface $connection,
        string $sObjectType,
        string $query,
        bool $updateEntity,
        bool $insertEntity = false
    ) {
        $client = $connection->getRestClient()->getSObjectClient();
        $query  = $client->query($query);
        do {
            $records = $query->getRecords();
            if (!empty($records)) {
                $objects = [];
                foreach ($records as $record) {
                    $record->__SOBJECT_TYPE__ = $sObjectType;
                    $object = $this->preProcessor->preProcess($record, $connection, $updateEntity, $insertEntity);
                    if (null === $object) {
                        continue;
                    }
                    $objects[] = $object;

                    if (count($objects) === $this->batchSize) {
                        $this->receiveObjects($connection, $updateEntity, $objects);
                        $objects = [];
                    }
                }

                if (!empty($objects)) {
                    $this->receiveObjects($connection, $updateEntity, $objects);
                }
            }
        } while (!($query = $client->query($query))->isDone());
    }

    /**
     * @param ConnectionInterface $connection
     * @param bool $updateEntity
     * @param array $objects
     *
     * @throws MappingException
     * @throws ORMException
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     */
    private function receiveObjects(ConnectionInterface $connection, bool $updateEntity, array $objects): void
    {
        $this->connector->enable();
        $this->connector->receive(
            $objects,
            SalesforceConsumerInterface::UPDATED,
            $connection->getName(),
            $updateEntity
        );
        $this->connector->disable();
    }
}
