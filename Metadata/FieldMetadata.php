<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 3:11 PM
 */

namespace AE\ConnectBundle\Metadata;

use AE\SalesforceRestSdk\Model\Rest\Metadata\Field;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\Common\Persistence\Proxy;
use JMS\Serializer\Annotation as Serializer;

class FieldMetadata extends AbstractFieldMetadata
{
    /**
     * @var string
     * @Serializer\Type("string")
     */
    protected $field;

    /**
     * @var bool
     * @Serializer\Type("bool")
     */
    protected $isIdentifier = false;

    /**
     * @var Metadata
     * @Serializer\Exclude()
     * @Serializer\Type("AE\ConnectBundle\Metadata\Metadata")
     */
    protected $metadata;

    /**
     * FieldMetadata constructor.
     *
     * @param Metadata $metadata
     * @param null|string $property
     * @param null|string $field
     * @param bool $isIdentifying
     */
    public function __construct(
        Metadata $metadata,
        ?string $property = null,
        ?string $field = null,
        bool $isIdentifying = false
    ) {
        $this->metadata     = $metadata;
        $this->property     = $property;
        $this->field        = $field;
        $this->isIdentifier = $isIdentifying;
    }

    /**
     * @return string|null
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * @param string|null $field
     *
     * @return FieldMetadata
     */
    public function setField(?string $field)
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
     * @return Field|null
     */
    public function describe(): ?Field
    {
        return null !== $this->field
            ? $this->metadata->describeField($this->field)
            : $this->metadata->describeFieldByProperty($this->property);
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
