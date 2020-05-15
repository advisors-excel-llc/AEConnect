<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

class Target
{
    public $name = '';
    public $query = '';
    public $count = 0;
    public $bulkOffset = 0;
    /** @var array|Record[] */
    public $records = [];

    public $sfidsCleared = false;
    public $queryComplete = false;
    public $batchSize;

    /** @var LocationQuery[] */
    private $locators = [];

    private $QBImpossible = false;
    private $QBImpossibleReason = '';

    public $temporaryTables = [];

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

    public function getSObjects(): array
    {
        return array_filter(array_map(function (Record $record) { return $record->sObject; }, $this->records));
    }

    /**
     * Get all entities, in the case of new sObjects which have passed the createEntityWithSObject step from an sObject that
     * is associated with a discriminated super entity, there will be several entities that were derived from the same sObject.
     * We will not know which of these entities is 'real' until validation step.
     * @return array
     */
    public function getEntities(): array
    {
        return array_filter(array_map(function (Record $record) { return $record->entity; }, $this->records));
    }

    public function getNewEntities(): array
    {
        return array_filter(array_map(
            function (Record $record) { return $record->entity; },
            array_filter($this->records, function (Record $record) { return $record->needPersist(); })
        ));
    }

    public function getUpdateEntities(): array
    {
        return array_filter(array_map(
            function (Record $record) { return $record->entity; },
            array_filter($this->records, function (Record $record) { return $record->needUpdate; })
        ));
    }

    /**
     * @return array|Record[]
     */
    public function getRecordsWithErrors(): array
    {
        return array_filter($this->records, function (Record $record) { return $record->error !== ''; });
    }

    public function getRecordsWithEntities(): array
    {
        return array_filter($this->records, function (Record $record) { return $record->entity !== null; });
    }

    /**
     * @return array|Record[]
     */
    public function getRecordsWithWarnings(): array
    {
        return array_filter($this->records, function (Record $record) { return $record->warning !== ''; });
    }

    public function canUpdate(): bool
    {
        return array_reduce($this->records, function (bool $carry, Record $record) { return $carry || $record->canUpdate(); }, false);
    }

    public function canCreateInDatabase(): bool
    {
        return array_reduce($this->records, function (bool $carry, Record $record) { return $carry || $record->canCreateInDatabase(); }, false);
    }

    public function canCreateInSalesforce(): bool
    {
        return array_reduce($this->records, function (bool $carry, Record $record) { return $carry || $record->canCreateInSalesforce(); }, false);
    }
}
