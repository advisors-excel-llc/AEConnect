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
use Doctrine\Persistence\ManagerRegistry;

class MultiValuePickListTransformer extends AbstractTransformerPlugin
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    public function __construct(ManagerRegistry $registry)
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
        $classMeta = $payload->getClassMetadata();
        $type      = $classMeta->getTypeOfField($payload->getPropertyName());
        $field     = $payload->getFieldMetadata()->describe();

        if (is_string($type)) {
            $type = Type::getType($type);
        }

        return is_string($value)
            && null !== $field
            && ($type instanceof ArrayType || $type instanceof JsonType || $type instanceof SimpleArrayType)
            && count($field->getPicklistValues()) > 0 && $field->getLength() === 4099;
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        $value = $payload->getValue();
        $field = $payload->getFieldMetadata()->describe();

        return is_array($value)
            && null !== $field
            && count($field->getPicklistValues()) > 0 && $field->getLength() === 4099;
    }

    /**
     * @param TransformerPayload $payload
     */
    public function transformInbound(TransformerPayload $payload)
    {
        $value = explode(';', $payload->getValue());

        $payload->setValue(
            $value
        );
    }

    /**
     * @param TransformerPayload $payload
     */
    public function transformOutbound(TransformerPayload $payload)
    {
        $value = $payload->getValue();
        $field = $payload->getFieldMetadata()->describe();

        if ($field->isRestrictedPicklist()) {
            $values = $field->getPicklistValues();
            $new    = [];
            foreach ($values as $item) {
                if ($item->isActive() && in_array($item->getValue(), $value)) {
                    $new[] = $item->getValue();
                }
            }
            $value = $new;
        }

        $payload->setValue(implode(';', $value));
    }

    public function getName(): string
    {
        return 'array';
    }
}
