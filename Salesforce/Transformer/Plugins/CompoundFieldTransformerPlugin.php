<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/1/18
 * Time: 8:58 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Doctrine\DBAL\Types\Type;

class CompoundFieldTransformerPlugin implements TransformerPluginInterface
{
    public function supports(TransformerPayload $payload): bool
    {
        return $payload->getDirection() === TransformerPayload::INBOUND &&
            is_array($payload->getValue()) &&
            $payload->getClassMetadata()->getTypeOfField($payload->getPropertyName()) === Type::STRING;
    }

    public function transform(TransformerPayload $payload)
    {
        /** @var array $value */
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();

        // If the Array is a compound field, the array keys should be other fields
        // If those fields exist in our mapping, then we need to update these values
        // to ensure they get mapped correctly
        foreach ($value as $field => $fieldValue) {
            $fieldMeta = $metadata->getMetadataForField($field);

            if (null !== $fieldMeta) {
                $payload->getSObject()->$field = $fieldValue;
            }
        }

        // Most of the time a space will suffice for delimiting. Anything else will need handled
        // via prePersist or preUpdate lifecycle events
        $payload->setValue(implode(' ', $value));
    }

    public function getName(): string
    {
        return 'compoundField';
    }
}
