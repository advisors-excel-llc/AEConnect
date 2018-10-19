<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 11:03 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Doctrine\DBAL\Types\ArrayType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SimpleArrayType;
use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\RegistryInterface;

class MultiValuePickListTransformer extends AbstractTransformerPlugin
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param TransformerPayload $payload
     *
     * @return bool
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function supportsInbound(TransformerPayload $payload): bool
    {
        $value     = $payload->getValue();
        $metadata  = $payload->getMetadata();
        $classMeta = $payload->getClassMetadata();
        $type      = $classMeta->getTypeOfField($metadata->getPropertyByField($payload->getFieldName()));
        $field     = $metadata->describeField($payload->getFieldName());

        if (is_string($type)) {
            $type = Type::getType($type);
        }

        return is_string($value) && false !== strpos($value, ';')
            && ($type instanceof ArrayType || $type instanceof JsonType || $type instanceof SimpleArrayType)
            && count($field->getPicklistValues()) > 0 && $field->getLength() === 4099;
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();
        $field    = $metadata->describeFieldByProperty($payload->getPropertyName());

        return is_array($value)
            && count($field->getPicklistValues()) > 0 && $field->getLength() === 4099;
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function transformInbound(TransformerPayload $payload)
    {
        $value     = $payload->getValue();
        $metadata  = $payload->getMetadata();
        $classMeta = $payload->getClassMetadata();
        $type      = $classMeta->getTypeOfField($metadata->getPropertyByField($payload->getFieldName()));
        $platform  = $this->registry->getEntityManagerForClass($classMeta->getName())
                                    ->getConnection()
                                    ->getDatabasePlatform()
        ;

        if (is_string($type)) {
            $type = Type::getType($type);
        }

        $payload->setValue(
            $type->convertToPHPValue(explode(';', $value), $platform)
        );
    }

    public function transformOutbound(TransformerPayload $payload)
    {
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();
        $field    = $metadata->describeFieldByProperty($payload->getPropertyName());

        if ($field->isRestrictedPicklist()) {
            $values = $field->getPicklistValues();
            $value  = array_intersect($value, $values);
        }

        $payload->setValue(implode(';', $value));
    }
}
