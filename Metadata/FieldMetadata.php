<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 3:11 PM
 */

namespace AE\ConnectBundle\Metadata;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\Common\Persistence\Proxy;
use JMS\Serializer\Annotation as Serializer;

class FieldMetadata extends AbstractFieldMetadata
{
    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $field;

    /**
     * @var bool
     * @Serializer\Type("bool")
     */
    private $isIdentifier = false;

    /**
     * FieldMetadata constructor.
     *
     * @param null|string $property
     * @param null|string $field
     * @param bool $isIdentifying
     */
    public function __construct(?string $property = null, ?string $field, bool $isIdentifying = false)
    {
        $this->property     = $property;
        $this->field        = $field;
        $this->isIdentifier = $isIdentifying;
    }

    /**
     * @return string
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @param string $field
     *
     * @return FieldMetadata
     */
    public function setField(string $field)
    {
        $this->field = $field;

        return $this;
    }

    /**
     * @return bool
     */
    public function isIdentifier(): bool
    {
        return $this->isIdentifier;
    }

    /**
     * @param bool $isIdentifier
     *
     * @return FieldMetadata
     */
    public function setIsIdentifier(bool $isIdentifier)
    {
        $this->isIdentifier = $isIdentifier;

        return $this;
    }

    /**
     * @param $entity
     *
     * @return mixed|null
     */
    public function getValueFromEntity($entity)
    {
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
     * @return FieldMetadata
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
