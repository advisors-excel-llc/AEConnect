<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/31/18
 * Time: 2:10 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Doctrine\DBAL\Types\Type;

class BooleanTransformerPlugin implements TransformerPluginInterface
{
    public function supports(TransformerPayload $payload): bool
    {
        return $payload->getDirection() === TransformerPayload::INBOUND
            && $payload->getClassMetadata()->getTypeOfField($payload->getPropertyName()) === Type::BOOLEAN
            ;
    }

    public function transform(TransformerPayload $payload)
    {
        $value = $payload->getValue();

        if (!is_bool($value)) {
            $value = $value == 1 || $value == "true" || $value == "True" || $value == "TRUE";
            $payload->setValue($value);
        }
    }
}
