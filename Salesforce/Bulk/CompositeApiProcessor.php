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

    public function __construct(BulkPreprocessor $preprocessor, SalesforceConnector $connector)
    {
        $this->preProcessor = $preprocessor;
        $this->connector    = $connector;
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $sObjectType
     * @param bool $updateEntity
     * @param string $query
     *
     * @throws \AE\SalesforceRestSdk\AuthProvider\SessionExpiredOrInvalidException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws MappingException
     * @throws ORMException
     */
    public function process(
        ConnectionInterface $connection,
        string $sObjectType,
        bool $updateEntity,
        string $query
    ) {
        $client = $connection->getRestClient()->getSObjectClient();
        $query  = $client->query($query);
        do {
            $records = $query->getRecords();
            if (!empty($records)) {
                foreach ($records as &$record) {
                    $record->__SOBJECT_TYPE__ = $sObjectType;
                    if (!$updateEntity) {
                        $record = $this->preProcessor->preProcess($record, $connection);
                    }
                }
                $this->connector->enable();
                $this->connector->receive(
                    $records,
                    SalesforceConsumerInterface::UPDATED,
                    $connection->getName(),
                    $updateEntity
                );
                $this->connector->disable();
            }
        } while (!($query = $client->query($query))->isDone());
    }
}
