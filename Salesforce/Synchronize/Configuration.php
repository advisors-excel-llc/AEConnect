<?php

namespace AE\ConnectBundle\Salesforce\Synchronize;

use AE\ConnectBundle\Salesforce\Synchronize\EventModel\Actions;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class Configuration
{
    private $connectionName;
    private $sObjectTargets = [];
    private $clearSFID = false;

    private $pull;
    private $push;

    /**
     * @var string[] Queries to use for RETRIEVE FROM SALESFORCE step
     */
    private $queries = [];

    private $debugModules = [
        'time'          => false,
        'memory'        => false,
        'database'      => false,
        'anaylsis'      => false,
    ];

    private $batchSize;

    public function __construct(string $connectionName, array $sObjectTargets, array $queries, bool $clearSFID, Actions $pull, Actions $push, int $batchSize = 50)
    {
        $this->connectionName = $connectionName;
        $this->sObjectTargets = $sObjectTargets;
        foreach ($queries as $query) {
            $this->addQuery('', $query);
        }
        $this->clearSFID = $clearSFID;

        $this->pull = $pull;
        $this->push = $push;
        $this->batchSize = $batchSize;
    }
    public function needsSFIDsCleared() : bool
    {
        return $this->clearSFID;
    }

    public function hasQueries() : bool
    {
        return (bool)count($this->queries);
    }

    public function needsTargetObjects() : bool
    {
        return !(bool)(count($this->queries) + count($this->sObjectTargets));
    }

    public function needsQueriesGenerated() : bool
    {
        return (bool)((!count($this->queries)) && count($this->sObjectTargets));
    }

    /**
     * RULES :
     * 1) You can't have queries and sObject targets at the same time
     * 2) You must have provided a Connection name
     */
    public function validateConfiguration() : bool
    {
        //1
        if (count($this->queries) && count($this->sObjectTargets) ) {
            throw new InvalidConfigurationException('You can\'t have queries and sObject targets at the same time');
        }
        //2
        if (!$this->connectionName) {
            throw new InvalidConfigurationException('You must have provided a Connection name');
        }
        return true;
    }

    /**
     * @return mixed
     */
    public function getConnectionName()
    {
        return $this->connectionName;
    }

    /**
     * @param mixed $connectionName
     */
    public function setConnectionName($connectionName): void
    {
        $this->connectionName = $connectionName;
    }

    /**
     * @return array
     */
    public function getSObjectTargets(): array
    {
        return $this->sObjectTargets;
    }

    /**
     * @param array $sObjectTargets
     */
    public function setSObjectTargets(array $sObjectTargets): void
    {
        $this->sObjectTargets = $sObjectTargets;
    }

    public function addQuery(string $target, string $query): void
    {
        if ($target == '') {
            $startOfTarget = trim(substr( $query,strpos($query, 'FROM') + 4));
            $target = trim(substr($startOfTarget, 0, strpos($startOfTarget, ' ')));
        }
        if (isset($this->queries[$target])) {
            throw new InvalidConfigurationException("Two queries were generated for the $target SObject.  Only one query can be processed per SObject at a time");
        }
        $this->queries[$target] = $query;
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getPullConfiguration(): Actions
    {
        return $this->pull;
    }

    public function getPushConfigurations(): Actions
    {
        return $this->push;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }
}