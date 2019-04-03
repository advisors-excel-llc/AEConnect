<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/30/18
 * Time: 2:54 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Doctrine\DBAL\Types\Type;
use Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType;
use Ramsey\Uuid\Doctrine\UuidBinaryType;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class UuidTransformerPlugin extends AbstractTransformerPlugin
{
    public function supportsInbound(TransformerPayload $payload): bool
    {
        $type = $payload->getClassMetadata()->getTypeOfField($payload->getPropertyName());

        if (is_string($type)) {
            $type = Type::getType($type);
        }

        return is_string($payload->getValue())
            && (
                $type instanceof UuidType
                || $type instanceof UuidBinaryType
                || $type instanceof UuidBinaryOrderedTimeType
            )
            ;
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        $field = $payload->getFieldMetadata()->describe();

        return $payload->getValue() instanceof UuidInterface
            && null !== $field
            && $field->getSoapType() === 'xsd:string'
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
