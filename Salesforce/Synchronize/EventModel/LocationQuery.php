<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\ORM\QueryBuilder;

class LocationQuery
{
    /** @var QueryBuilder */
    private $qb;

    private $externalIds = [];
    private $sfid;
    private $connection;

    private $getters = [];

    public function addExternalId(string $property, string $field): void
    {
        $this->externalIds[] = ['property' => $property, 'field' => $field];
    }

    public function getIdGetters(): array
    {
        if (empty($this->getters)) {
            foreach ($this->externalIds as $externalId) {
                $this->getters[] = [ 'entity' => 'get'.ucfirst($externalId['property']),
                                     'sObject' => 'get'.ucfirst($externalId['field']) ];
            }
            if (is_string($this->sfid)) {
                $this->getters[] = [ 'entity' => 'get'.ucfirst($this->sfid),
                                     'sObject' => 'getId' ];
            }
        }
        return $this->getters;
    }

    public function addSfidField(string $property): void
    {
        $this->sfid = $property;
    }

    public function addSfidAssociation(string $class, string $sfidAssociationField, string $sfidProperty)
    {
        $this->sfid = [
            'class'       => $class,
            'association' => $sfidAssociationField,
            'property'       => $sfidProperty
        ];
    }

    public function getSfid()
    {
        return $this->sfid;
    }

    public function addConnection(string $connectionProperty, $connectionName)
    {
        $this->connection = [
            'name' => $connectionName,
            'property' => $connectionProperty
        ];
    }

    public function setQb(QueryBuilder $qb)
    {
        $this->qb = $qb;
    }

    public function isOK(): bool
    {
        //For locator to be OK, it needs either an SFID location or an external ID
        return (bool)$this->qb && ($this->sfid || count($this->externalIds));
    }

    /**
     * @param array $sObjects
     * @return array
     */
    public function executeQuery(array $sObjects): array
    {
        if (!$this->isOK()) {
            return [];
        }

        $sfidClause = $this->qb->expr()->orX();
        $extIdClause = $this->qb->expr()->andX();

        $noNull = function($item) { return (bool)$item; };

        $sfidsFromSObjects = [];
        $extIdsFromSObjects = [];


        //If we have an SFID, lets build a query to select our entities based on the SFID value given.
        if ($this->sfid) {
            $sfidsFromSObjects = array_map(function (CompositeSObject $sObject) { return $sObject->getId(); }, $sObjects);
            $ids = array_filter($sfidsFromSObjects, $noNull);
            if (is_array($this->sfid)) {
                $this->qb->addSelect('sfid');
                $this->qb->leftJoin("e.{$this->sfid['property']}", 'sfid');
                $sfidClause->add($this->qb->expr()->in("sfid.{$this->sfid['association']}", $ids));
            } else if (is_string($this->sfid)) {
                $sfidClause->add($this->qb->expr()->in("e.$this->sfid", $ids));
            }
        }

        foreach ($this->externalIds as $externalId) {
            $getter = 'get' . ucfirst($externalId['field']);
            $extIds = array_map(function (CompositeSObject $sObject) use ($getter) { return $sObject->$getter(); }, $sObjects);
            $extIdsFromSObjects[] = $extIds;
            $extIds = array_filter($extIds, $noNull);
            $extIdClause->add($this->qb->expr()->in("e.{$externalId['property']}", $extIds));
        }
        $sfidClause->add($extIdClause);

        if ($this->connection) {
            $this->qb->where("e.{$this->connection['property']}");
            $this->qb->andWhere($sfidClause);
        } else {
            $this->qb->where($sfidClause);
        }

        $entities = $this->qb->getQuery()->getResult();
        return $entities;
    }
}
