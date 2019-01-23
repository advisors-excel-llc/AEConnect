<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 1:19 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\SalesforceRestSdk\Model\Rest\Metadata\Field;
use Doctrine\ORM\Mapping\ClassMetadata;

class TransformerPayload
{
    public const INBOUND  = 0;
    public const OUTBOUND = 1;
    /**
     * @var mixed
     */
    private $value;

    /**
     * @var string
     */
    private $propertyName;

    /**
     * @var string
     */
    private $fieldName;

    /**
     * @var mixed
     */
    private $entity;

    /**
     * @var Metadata
     */
    private $metadata;

    /**
     * @var ClassMetadata
     */
    private $classMetadata;

    /**
     * @var FieldMetadata|null
     */
    private $fieldMetadata;

    /**
     * @var int
     */
    private $direction;

    public function __construct(int $direction)
    {
        $this->setDirection($direction);
    }

    public static function inbound()
    {
        return new static(self::INBOUND);
    }

    public static function outbound()
    {
        return new static(self::OUTBOUND);
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     *
     * @return TransformerPayload
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getPropertyName(): ?string
    {
        return $this->propertyName;
    }

    /**
     * @param string $propertyName
     *
     * @return TransformerPayload
     */
    public function setPropertyName(?string $propertyName): TransformerPayload
    {
        $this->propertyName = $propertyName;

        return $this;
    }

    /**
     * @return string
     */
    public function getFieldName(): ?string
    {
        return $this->fieldName;
    }

    /**
     * @param string|null $fieldName
     *
     * @return TransformerPayload
     */
    public function setFieldName(?string $fieldName): TransformerPayload
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * @param mixed $entity
     *
     * @return TransformerPayload
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * @return Metadata
     */
    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * @param Metadata $metadata
     *
     * @return TransformerPayload
     */
    public function setMetadata(Metadata $metadata): TransformerPayload
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @return ClassMetadata
     */
    public function getClassMetadata(): ?ClassMetadata
    {
        return $this->classMetadata;
    }

    /**
     * @param ClassMetadata $classMetadata
     *
     * @return TransformerPayload
     */
    public function setClassMetadata(ClassMetadata $classMetadata): TransformerPayload
    {
        $this->classMetadata = $classMetadata;

        return $this;
    }

    /**
     * @return FieldMetadata|null
     */
    public function getFieldMetadata(): ?FieldMetadata
    {
        return $this->fieldMetadata;
    }

    /**
     * @param FieldMetadata|null $fieldMetadata
     *
     * @return TransformerPayload
     */
    public function setFieldMetadata(?FieldMetadata $fieldMetadata): TransformerPayload
    {
        $this->fieldMetadata = $fieldMetadata;

        return $this;
    }

    /**
     * @return int
     */
    public function getDirection(): int
    {
        return $this->direction;
    }

    /**
     * @param int $direction
     *
     * @return TransformerPayload
     */
    public function setDirection(int $direction): TransformerPayload
    {
        if (!in_array($direction, [self::INBOUND, self::OUTBOUND])) {
            throw new \InvalidArgumentException("Invalid value for direction.");
        }
        $this->direction = $direction;

        return $this;
    }
}
