<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 9:48 AM
 */

namespace AE\ConnectBundle\Serializer;

use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use AE\SalesforceRestSdk\Model\Rest\Composite\CompositeSObject;
use AE\SalesforceRestSdk\Serializer\CompositeSObjectHandler;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\JsonDeserializationVisitor;

class CompositeSObjectSubscriber extends CompositeSObjectHandler
{
    public function deserializeCompositeSObjectFromjson(
        JsonDeserializationVisitor $visitor,
        array $data,
        array $type,
        DeserializationContext $context
    ) {
        $sObject = parent::deserializeCompositeSObjectFromjson(
            $visitor,
            $data,
            $type,
            $context
        );

        $metadata = $context->getMetadataFactory()->getMetadataForClass(CompositeSObject::class);
        $visitor->startVisitingObject($metadata, $sObject, $type, $context);

        // Find and deserialize any ReferencePlaceholders that may exist
        foreach ($sObject->getFields() as $field => $value) {
            if (is_array($value) && in_array('entityRefId', array_keys($value))) {
                $sObject->$field = $context->getNavigator()->accept(
                    $value,
                    ['name' => ReferencePlaceholder::class],
                    $context
                )
                ;
            }
        }

        $visitor->endVisitingObject($metadata, $sObject, $type, $context);

        return $sObject;
    }
}
