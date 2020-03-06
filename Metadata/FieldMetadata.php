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

/**
 * Class FieldMetadata
 *
 * @package AE\ConnectBundle\Metadata
 */
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
     * @var string
     * @Serializer\Type("string")
     */
    protected $transformer;

    /**
     * @var Metadata
     * @Serializer\Exclude()
     * @Serializer\Type("AE\ConnectBundle\Metadata\Metadata")
     */
    protected $metadata;

    /**
     * FieldMetadata constructor.
     * @param Metadata $metadata
     * @param string|null $property
     * @param string|null $field
     * @param string|null $transformer
     * @param bool $isIdentifying
     */
    public function __construct(
        Metadata $metadata,
        ?string $property = null,
        ?string $field = null,
        ?string $transformer = null,
        bool $isIdentifying = false
    ) {
        $this->metadata     = $metadata;
        $this->property     = $property;
        $this->field        = $field;
        $this->transformer  = $transformer;
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

    public function getTransformer(): ?string
    {
        return $this->transformer;
    }

    public function setTransformer(?string $transformer)
    {
        $this->transformer = $transformer;
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
     * @return Metadata
     */
    public function getMetadata(): ?Metadata
    {
        return $this->metadata;
    }

    /**
     * @param Metadata $metadata
     *
     * @return FieldMetadata
     */
    public function setMetadata(?Metadata $metadata): FieldMetadata
    {
        $this->metadata = $metadata;

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
        $refClass  = ClassUtils::newReflectionObject($entity);
        $className = $refClass->getName();

        if ($entity instanceof Proxy && !$entity->__isInitialized()) {
            $entity->__load();
        }

        if (null !== $this->getter && method_exists($className, $this->getter)) {
            $method = $refClass->getMethod($this->getter);
            $method->setAccessible(true);

            return call_user_func($method->getClosure($entity));
        }

        if (null !== $this->property && property_exists($className, $this->property)) {
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
        $refClass  = ClassUtils::newReflectionObject($entity);
        $className = $refClass->getName();

        if ($entity instanceof Proxy && !$entity->__isInitialized()) {
            $entity->__load();
        }

        if (null !== $this->setter && method_exists($className, $this->setter)) {
            $method = $refClass->getMethod($this->setter);
            $method->setAccessible(true);

            call_user_func($method->getClosure($entity), $value);

            return $this;
        }

        if (null !== $this->property && property_exists($className, $this->property)) {
            $property = $refClass->getProperty($this->property);
            $property->setAccessible(true);

            $property->setValue($entity, $value);
        }

        return $this;
    }
}
