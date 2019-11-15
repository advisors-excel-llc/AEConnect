<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Persistence\ObjectManager;

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

    public function getSObjects(): array
    {
        return array_filter(array_map(function (Record $record) { return $record->sObject; }, $this->records));
    }

    public function getEntities(): array
    {
        return array_filter(array_map(function (Record $record) { return $record->entity; }, $this->records));
    }

    public function getNewEntities(): array
    {
        return array_filter(array_map(
            function (Record $record) { return $record->entity; },
            array_filter($this->records, function (Record $record) { return $record->needPersist; })
        ));
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
