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

class UuidTransformerPlugin implements TransformerPluginInterface
{
    public function supports(TransformerPayload $payload): bool
    {
        return $payload->getDirection() === TransformerPayload::INBOUND
            && is_string($payload->getValue())
            && $payload->getClassMetadata()->getTypeOfField($payload->getPropertyName()) instanceof UuidType
            ;
    }

    public function transform(TransformerPayload $payload)
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
}
