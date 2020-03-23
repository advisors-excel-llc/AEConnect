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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class DateTimeTransformer extends AbstractTransformerPlugin
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    protected function supportsInbound(TransformerPayload $payload): bool
    {
        $value = $payload->getValue();
        $field = $payload->getFieldMetadata()->describe();

        return (is_string($value) || $value instanceof \DateTimeInterface)
            && null !== $field
            && in_array(strtolower($field->getSoapType()), ['xsd:date', 'xsd:datetime', 'xsd:time']);
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        $value = $payload->getValue();
        $field = $payload->getFieldMetadata()->describe();

        return $value instanceof \DateTimeInterface
            && null !== $field
            && in_array(strtolower($field->getSoapType()), ['xsd:date', 'xsd:datetime', 'xsd:time']);
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws DBALException
     */
    public function transformInbound(TransformerPayload $payload)
    {
        /** @var EntityManagerInterface $manager */
        $manager  = $this->registry->getManagerForClass($payload->getClassMetadata()->getName());
        $platform = $manager->getConnection()
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


        $dateTime = '_immutable' === (substr($type->getName(), -10))
            ? new \DateTimeImmutable($value)
            : new \DateTime($value);

        if ($dateTime) {
            $payload->setValue($dateTime);

            return;
        }

        $payload->setValue(
            strlen($value) > 0 ? $type->convertToPHPValue($value, $platform) : null
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
