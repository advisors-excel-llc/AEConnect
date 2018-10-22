<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 12:10 PM
 */

namespace AE\ConnectBundle\Metadata;

use Doctrine\Common\Persistence\Proxy;
use Doctrine\Common\Util\ClassUtils;
use JMS\Serializer\Annotation as Serializer;

class RecordTypeMetadata extends FieldMetadata
{
    /**
     * @var string|null
     * @Serializer\Type("string")
     */
    private $name;

    public function __construct(?string $name = null, ?string $propertyName = null)
    {
        parent::__construct($propertyName, 'RecordTypeId', false);
        $this->name = $name;
    }

    /**
     * @return null|string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param null|string $name
     *
     * @return RecordTypeMetadata
     */
    public function setName(?string $name): RecordTypeMetadata
    {
        $this->name = $name;

        return $this;
    }

    final public function setField(string $field)
    {
        // Intentionally left blank
        return $this;
    }

    final public function setIsIdentifier(bool $isIdentifier)
    {
        // Intentionally left blank
        return $this;
    }

    /**
     * @param $entity
     *
     * @return mixed|null
     */
    public function getValueFromEntity($entity)
    {
        // if the name is set, that wins overall
        if (null !== $this->name) {
            return $this->name;
        }

        $refClass = ClassUtils::newReflectionObject($entity);

        if ($entity instanceof Proxy && !$entity->__isInitialized()) {
            $entity->__load();
        }

        if (null !== $this->getter && method_exists($entity, $this->getter)) {
            $method = $refClass->getMethod($this->getter);
            $method->setAccessible(true);

            return $method->getClosure($entity)->call($entity);
        }

        if (null !== $this->property && property_exists($entity, $this->property)) {
            $property = $refClass->getProperty($this->property);
            $property->setAccessible(true);

            return $property->getValue($entity);
        }

        return null;
    }

    /**
     * @param $entity
     * @param $value
     *
     * @return RecordTypeMetadata
     */
    public function setValueForEntity($entity, $value)
    {
        $refClass = ClassUtils::newReflectionObject($entity);

        if (null !== $this->setter && method_exists($entity, $this->setter)) {
            $method = $refClass->getMethod($this->setter);
            $method->setAccessible(true);

            $method->getClosure($entity)->call($entity, $value);

            return $this;
        }

        if (null !== $this->property && property_exists($entity, $this->property)) {
            $property = $refClass->getProperty($this->property);
            $property->setAccessible(true);

            $property->setValue($entity, $value);
        }

        return $this;
    }
}
