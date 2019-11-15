<?php

namespace AE\ConnectBundle\Salesforce\Synchronize\EventModel;

use AE\SalesforceRestSdk\Model\SObject;

class Record
{
    /** @var SObject|null */
    public $sObject;
    /** @var object|null  */
    public $entity;
    /** @var bool */
    public $needPersist = false;
    /** @var string  */
    public $error = '';

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

    public function matchEntityToSObject(array $entities, LocationQuery $locator): void
    {
        //Try this fast getters first
        foreach($entities as $entity) {
            foreach ($locator->getIdGetters() as $getter) {
                if ($entity->{$getter['entity']}() === $this->sObject->{$getter['sObject']}()) {
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
