<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 2:35 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

class RecordTypeTransformer extends AbstractTransformerPlugin
{
    public function supports(TransformerPayload $payload): bool
    {
        return null !== $payload->getMetadata()->getRecordType() && $payload->getFieldName() === 'RecordTypeId';
    }

    protected function transformInbound(TransformerPayload $payload)
    {
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();

        if (null !== $value && null !== ($recordTypeName = $metadata->getRecordTypeDeveloperName($value))) {
            $payload->setValue($recordTypeName);
        }
    }

    protected function transformOutbound(TransformerPayload $payload)
    {
        $metadata     = $payload->getMetadata();
        $recordTypeId = $metadata->getRecordTypeId($payload->getValue());

        $payload->setValue($recordTypeId);
    }
}
