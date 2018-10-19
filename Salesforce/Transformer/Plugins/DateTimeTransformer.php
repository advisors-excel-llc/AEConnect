<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 9:53 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\RegistryInterface;

class DateTimeTransformer extends AbstractTransformerPlugin
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    public function __construct(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    protected function supportsInbound(TransformerPayload $payload): bool
    {
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();
        $field    = $metadata->describeField($payload->getFieldName());

        return ((is_string($value) && strlen($value) > 0) || $value instanceof \DateTimeInterface)
            && in_array(strtolower($field->getSoapType()), ['xsd:date', 'xsd:datetime', 'xsd:time']);
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        $value    = $payload->getValue();
        $metadata = $payload->getMetadata();
        $field    = $metadata->describeFieldByProperty($payload->getPropertyName());

        return $value instanceof \DateTimeInterface
            && in_array(strtolower($field->getSoapType()), ['xsd:date', 'xsd:datetime', 'xsd:time']);
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws DBALException
     */
    public function transformInbound(TransformerPayload $payload)
    {
        $platform = $this->registry->getEntityManagerForClass($payload->getClassMetadata()->getName())
                                   ->getConnection()
                                   ->getDatabasePlatform()
        ;

        $value     = $payload->getValue();
        $metadata  = $payload->getMetadata();
        $classMeta = $payload->getClassMetadata();
        $type      = $classMeta->getTypeOfField($metadata->getPropertyByField($payload->getFieldName()));

        // The serializer could create a \DateTime object, which could cause problems here
        // in the event that the database just wanted date or time or datetimeimmutable or something.
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(\DATE_ISO8601);
        }

        if (is_string($type)) {
            $type = Type::getType($type);
        }

        $payload->setValue(
            $type->convertToPHPValue($value, $platform)
        );
    }

    /**
     * @param TransformerPayload $payload
     */
    public function transformOutbound(TransformerPayload $payload)
    {
        /** @var \DateTimeInterface $value */
        $value = clone $payload->getValue();

        if ($value instanceof \DateTime) {
            $value->setTimezone(new \DateTimeZone('UTC'));
        }

        $payload->setValue($value->format(\DATE_ISO8601));
    }
}
