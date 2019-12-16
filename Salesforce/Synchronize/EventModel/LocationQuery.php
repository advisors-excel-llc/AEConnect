<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class LocationQuery
{
    /** @var RegistryInterface */
    private $registry;
    private $className;

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

    public function setRepository(RegistryInterface $registry, string $className)
    {
        $this->registry = $registry;
        $this->className = $className;
    }

    /**
     * @return ObjectRepository|EntityRepository
     */
    public function getRepository(): ObjectRepository
    {
        return $this->registry->getRepository($this->className);
    }

    public function isOK(): bool
    {
        //For locator to be OK, it needs either an SFID location or an external ID to base its queries off of.
        return (bool)$this->registry && ($this->sfid || count($this->externalIds));
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

        $qb = $this->getRepository()->createQueryBuilder('e');

        $sfidClause = $qb->expr()->orX();
        $extIdClause = $qb->expr()->andX();

        //If we have an SFID, lets build a query to select our entities based on the SFID value given.
        if ($this->sfid) {
            $ids = array_filter(array_map(function (CompositeSObject $sObject) { return $sObject->getId(); }, $sObjects));
            if (is_array($this->sfid)) {
                $qb->addSelect('sfid');
                $qb->leftJoin("e.{$this->sfid['property']}", 'sfid');
                $sfidClause->add($qb->expr()->in("sfid.{$this->sfid['association']}", $ids));
            } else if (is_string($this->sfid)) {
                $sfidClause->add($qb->expr()->in("e.$this->sfid", $ids));
            }
        }

        foreach ($this->externalIds as $externalId) {
            $getter = 'get' . ucfirst($externalId['field']);
            $extIds = array_map(function (CompositeSObject $sObject) use ($getter) { return $sObject->$getter(); }, $sObjects);
            $extIds = array_filter($extIds);
            if (count($extIds)) {
                $extIdClause->add($qb->expr()->in("e.{$externalId['property']}", $extIds));
            }
        }
        $sfidClause->add($extIdClause);

        if ($this->connection) {
            $qb->where("e.{$this->connection['property']}");
            $qb->andWhere($sfidClause);
        } else {
            $qb->where($sfidClause);
        }

        $entities = $qb->getQuery()->getResult();
        return $entities;
    }
}
