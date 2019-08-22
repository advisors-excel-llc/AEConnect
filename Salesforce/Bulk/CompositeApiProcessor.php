<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 3/12/19
 * Time: 4:51 PM
 */

namespace AE\ConnectBundle\Salesforce\Bulk;

use AE\ConnectBundle\Connection\ConnectionInterface;
use AE\ConnectBundle\Salesforce\SalesforceConnector;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;

class CompositeApiProcessor extends AbstractApiProcessor
{
    /**
     * @var BulkPreprocessor
     */
    private $preProcessor;

    /**
     * CompositeApiProcessor constructor.
     *
     * @param BulkPreprocessor $preprocessor
     * @param SalesforceConnector $connector
     * @param BulkProgress $progress
     * @param int $batchSize
     */
    public function __construct(
        BulkPreprocessor $preprocessor,
        SalesforceConnector $connector,
        BulkProgress $progress,
        int $batchSize = 50
    ) {
        parent::__construct($connector, $progress, $batchSize);
        $this->preProcessor = $preprocessor;
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
                        $this->receiveObjects($sObjectType, $connection, $updateEntity, $objects);
                        $objects = [];
                    }
                }

                if (!empty($objects)) {
                    $this->receiveObjects($sObjectType, $connection, $updateEntity, $objects);
                }
            }
        } while (!($query = $client->query($query))->isDone());
    }
}
