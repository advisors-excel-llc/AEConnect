<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

use AE\SalesforceRestSdk\Model\SObject;
use Ramsey\Uuid\Uuid;

class Record
{
    /** @var SObject|null */
    public $sObject;
    /** @var object|null */
    public $entity;
    /** @var bool - An entity which has been marked as needing update.
     * This is true AFTER successful deserialization on an sObject for which a pre existing entity was located in the database.
     */
    public $needUpdate = false;
    /** @var bool - An entity which has been marked as needing create
     * This is true AFTER successful deserialization on an sObject for which a pre existing entity was NOT located in the database.
     */
    public $needCreate = false;
    /** @var bool - An entity which has passed symfony Validation successfully. */
    public $valid = false;
    /** @var string  */
    public $error = '';
    /** @var string */
    public $warning = '';

    public function __construct(?SObject $sObject = null, ?object $entity = null)
    {
        $this->sObject = $sObject;
        $this->entity = $entity;
    }

    public function canUpdate(): bool
    {
        return $this->sObject !== null && $this->entity !== null;
    }

    public function canCreateInSalesforce(): bool
    {
        return $this->sObject === null && $this->entity !== null;
    }

    public function canCreateInDatabase(): bool
    {
        return $this->sObject !== null && $this->entity === null;
    }

    /**
     * An entity is only said to need to be persisted if it has both passed validation and is marked for needing creation.
     * @return bool
     */
    public function needPersist(): bool
    {
        return $this->valid && $this->needCreate;
    }

    public function matchEntityToSObject(array $entities, LocationQuery $locator): void
    {
        //Try this fast getters first
        foreach($entities as $entity) {
            foreach ($locator->getIdGetters() as $getter) {
                $entityVal = $entity->{$getter['entity']}();
                $entityVal = method_exists($entityVal, '__toString') ? $entityVal->__toString() : $entityVal;
                $sObjectValue = $this->sObject->{$getter['sObject']}();

                if ($entityVal === $sObjectValue) {
                    $this->entity = $entity;
                    return;
                }
            }
        }

        //If there wasn't a single entity amongst them we can try this more difficult sfid nabber... but most of the time
        // external key will do OK
        $sfidAssociation = $locator->getSfid();
        if (is_array($sfidAssociation)) {
            foreach($entities as $entity) {
                //If the SFID was an association, we have to first get the object containing the SFID before we can get the actual SFID.
                $sfidProperty   = $sfidAssociation['property'];
                $sfidGetter     = 'get'.ucfirst($sfidProperty);
                $sfidObj        = $entity->$sfidGetter();

                //If the relationship is MANY_TO we will have to extract the first sfid object like so,
                if (method_exists($sfidObj, 'toArray')) {
                    $sfidObj = $sfidObj->toArray();
                }
                if (is_array($sfidObj) && count($sfidObj)) {
                    $sfidObj = $sfidObj[0];
                }
                $sfidObjGetter = 'get'.ucfirst($sfidAssociation['association']);
                if (method_exists($sfidObj, $sfidObjGetter)) {
                     if ($sfidObj->$sfidObjGetter() === $this->sObject->getId()) {
                         $this->entity = $entity;
                         return;
                    }
                }
            }
        }
    }
}
