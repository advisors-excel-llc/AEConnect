<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/30/18
 * Time: 2:54 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class UuidTransformerPlugin extends AbstractTransformerPlugin
{
    public function supportsInbound(TransformerPayload $payload): bool
    {
        return is_string($payload->getValue())
            && $payload->getClassMetadata()->getTypeOfField($payload->getPropertyName()) instanceof UuidType
            ;
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        return $payload->getValue() instanceof UuidInterface
            && $payload->getMetadata()->getMetadataForField($payload->getFieldName())
                                      ->describe()
                                      ->getSoapType() === 'xsd:string'
            ;
    }

    public function transformInbound(TransformerPayload $payload)
    {
        $value = $payload->getValue();

        if (strlen($value) === 0) {
            $payload->setValue(null);
        } else {
            $payload->setValue(
                Uuid::fromString($value)
            );
        }
    }

    protected function transformOutbound(TransformerPayload $payload)
    {
        /** @var UuidInterface $value */
        $value = $payload->getValue();

        $payload->setValue($value->toString());
    }
}
