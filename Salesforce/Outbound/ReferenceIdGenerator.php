<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 1:29 PM
 */

namespace AE\ConnectBundle\Salesforce\Outbound;

use AE\ConnectBundle\Metadata\Metadata;
use AE\SalesforceRestSdk\Model\SObject;

class ReferenceIdGenerator
{
    /**
     * @param SObject $entity
     * @param Metadata $metadata
     *
     * @return string
     */
    public static function create(SObject $entity, Metadata $metadata): ?string
    {
        $fields = $metadata->getIdentifyingFields();

        if (empty($properties)) {
            return null;
        }

        $refId = $metadata->getClassName();

        foreach ($fields as $field) {
            $refId .= '|'.$entity->$field;
        }

        return md5($refId);
    }
}
