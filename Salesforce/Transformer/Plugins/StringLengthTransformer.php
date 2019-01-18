<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/10/19
 * Time: 5:51 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

class StringLengthTransformer extends AbstractTransformerPlugin
{
    public function supportsOutbound(TransformerPayload $payload): bool
    {
        $field = $payload->getFieldMetadata()->describe();

        return is_string($payload->getValue())
            && null !== $field
            && $field->getSoapType() === 'xsd:string'
            && null !== $field->getLength()
            && 0 < $field->getLength()
            ;
    }

    protected function transformOutbound(TransformerPayload $payload)
    {
        $field  = $payload->getFieldMetadata()->describe();
        $value  = $payload->getValue();
        $length = $field->getLength();

        $value = substr($value, 0, $length);

        $payload->setValue($value);
    }
}
