<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

class Target
{
    public $name = '';
    public $query = '';
    public $count = 0;
    /** @var array|Record[] */
    public $records = [];

    public $sfidsCleared = false;
    public $queryComplete = false;
    public $batchSize;

    /** @var LocationQuery[] */
    private $locators = [];

    private $QBImpossible = false;
    private $QBImpossibleReason = '';

    /**
     * @return LocationQuery[]
     */
    public function getLocators(): array
    {
        return $this->locators;
    }

    public function executeLocators(): void
    {
        foreach ($this->locators as $locator) {
            $entities = $locator->executeQuery($this->getSObjects());
            foreach ($this->records as $record) {
                if ($record->entity) {
                    //already found!
                    continue;
                }
                $record->matchEntityToSObject($entities, $locator);
            }
        }
    }

    public function addLocator(LocationQuery $query): void
    {
        $this->locators[] = $query;
    }

    public function isQBImpossible(): bool
    {
        return $this->QBImpossible;
    }

    public function setQBImpossible(bool $impossible, string $reason): void
    {
        $this->QBImpossible = $impossible;
        $this->QBImpossibleReason = $reason;
    }

    public function getSObjects()
    {
        return array_map(function (Record $record) { return $record->sObject; }, $this->records);
    }

    public function getEntities()
    {
        return array_map(function (Record $record) { return $record->entity; }, $this->records);
    }
}
