<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 1:19 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Metadata\Metadata;
use Doctrine\ORM\Mapping\ClassMetadata;

class TransformerPayload
{
    public const INBOUND  = 0;
    public const OUTBOUND = 1;
    /**
     * @var mixed
     */
    private $payload;

    /**
     * @var string
     */
    private $propertyName;

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
     * @var string|null
     */
    private $refId;

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
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @param mixed $payload
     *
     * @return TransformerPayload
     */
    public function setPayload($payload)
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * @return string
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * @param string $propertyName
     *
     * @return TransformerPayload
     */
    public function setPropertyName(string $propertyName): TransformerPayload
    {
        $this->propertyName = $propertyName;

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
    public function getClassMetadata(): ClassMetadata
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
     * @return null|string
     */
    public function getRefId(): ?string
    {
        return $this->refId;
    }

    /**
     * @param null|string $refId
     *
     * @return TransformerPayload
     */
    public function setRefId(?string $refId): TransformerPayload
    {
        $this->refId = $refId;

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
