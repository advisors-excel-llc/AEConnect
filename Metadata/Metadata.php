<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 1:55 PM
 */

namespace AE\ConnectBundle\Metadata;

use AE\SalesforceRestSdk\Model\Rest\Metadata\DescribeSObject;
use AE\SalesforceRestSdk\Model\Rest\Metadata\Field;
use Doctrine\Common\Collections\ArrayCollection;
use JMS\Serializer\Annotation as Serializer;

/**
 * Class Metadata
 *
 * @package AE\ConnectBundle\Metadata
 */
class Metadata
{
    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $className;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $sObjectType;

    /**
     * @var ArrayCollection<string, string>
     * @Serializer\Type("ArrayCollection")
     */
    private $propertyMap;

    /**
     * @var ArrayCollection<string>
     * @Serializer\Type("ArrayCollection")
     */
    private $identifiers;

    /**
     * @var DescribeSObject
     * @Serializer\Type("array")
     */
    private $describe;

    /**
     * @var string
     * @Serializer\Type("string")
     */
    private $connectionName;

    /**
     * Metadata constructor.
     *
     * @param string $connectionName
     */
    public function __construct(string $connectionName)
    {
        $this->propertyMap    = new ArrayCollection();
        $this->identifiers    = new ArrayCollection();
        $this->connectionName = $connectionName;
    }

    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @param string $className
     *
     * @return Metadata
     */
    public function setClassName(string $className): Metadata
    {
        $this->className = $className;

        return $this;
    }

    /**
     * @return string
     */
    public function getSObjectType(): string
    {
        return $this->sObjectType;
    }

    /**
     * @param string $sObjectType
     *
     * @return Metadata
     */
    public function setSObjectType(string $sObjectType): Metadata
    {
        $this->sObjectType = $sObjectType;

        return $this;
    }

    /**
     * @return ArrayCollection
     */
    public function getPropertyMap(): ArrayCollection
    {
        return $this->propertyMap;
    }

    /**
     * @param ArrayCollection $propertyMap
     *
     * @return Metadata
     */
    public function setPropertyMap(ArrayCollection $propertyMap): Metadata
    {
        $this->propertyMap = $propertyMap;

        return $this;
    }

    public function getFieldMap() : ArrayCollection
    {
        $map = $this->propertyMap->toArray();

        return new ArrayCollection(array_flip($map));
    }

    /**
     * @param string $propertyName
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function addField(string $propertyName, string $fieldName): Metadata
    {
        $this->propertyMap->set($propertyName, $fieldName);

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function removeField(string $fieldName): Metadata
    {
        $this->propertyMap->removeElement($fieldName);

        return $this;
    }

    /**
     * @return null|string
     */
    public function getIdFieldProperty(): ?string
    {
        return $this->getPropertyByField('Id');
    }

    /**
     * @param string $propertyName
     *
     * @return null|string
     */
    public function getFieldByProperty(string $propertyName): ?string
    {
        return $this->propertyMap->get($propertyName);
    }

    /**
     * @param string $fieldName
     *
     * @return null|string
     */
    public function getPropertyByField(string $fieldName): ?string
    {
        $prop = $this->propertyMap->indexOf($fieldName);

        return false === $prop ? null : $prop;
    }

    /**
     * @param string $propertyName
     *
     * @return Metadata
     */
    public function removeProperty(string $propertyName): Metadata
    {
        $this->propertyMap->remove($propertyName);

        return $this;
    }

    /**
     * @return ArrayCollection
     */
    public function getIdentifiers(): ArrayCollection
    {
        return $this->identifiers;
    }

    /**
     * @param ArrayCollection $identifiers
     *
     * @return Metadata
     */
    public function setIdentifiers(ArrayCollection $identifiers): Metadata
    {
        $this->identifiers = $identifiers;

        return $this;
    }

    /**
     * @param string $propertyName
     *
     * @return Metadata
     */
    public function addIdentifier(string $propertyName): Metadata
    {
        if (!$this->identifiers->contains($propertyName)) {
            $this->identifiers->add($propertyName);
        }

        return $this;
    }

    /**
     * @param string $propertyName
     *
     * @return Metadata
     */
    public function removeIdentifier(string $propertyName): Metadata
    {
        $this->identifiers->removeElement($propertyName);

        return $this;
    }

    /**
     * @param string $propertyName
     *
     * @return bool
     */
    public function isIdentifier(string $propertyName): bool
    {
        return $this->identifiers->contains($propertyName);
    }

    /**
     * @return array
     */
    public function getIdentifyingFields(): array
    {
        $props = $this->getIdentifiers();
        $fields = [];

        foreach ($props as $prop) {
            $fields[$prop] = $this->getFieldByProperty($prop);
        }

        return $fields;
    }

    /**
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function addIdentifyingField(string $fieldName): Metadata
    {
        $propertyName = $this->propertyMap->indexOf($fieldName);

        if (false !== $propertyName) {
            $this->addIdentifier($propertyName);
        }

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function removeIdentifyingField(string $fieldName): Metadata
    {
        $propertyName = $this->propertyMap->indexOf($fieldName);

        if (false !== $propertyName) {
            $this->removeIdentifier($propertyName);
        }

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return bool
     */
    public function isIdentifyingField(string $fieldName): bool
    {
        $propertyName = $this->propertyMap->indexOf($fieldName);

        return false !== $propertyName && $this->isIdentifier($propertyName);
    }

    /**
     * @return DescribeSObject|null
     */
    public function getDescribe(): ?DescribeSObject
    {
        return $this->describe;
    }

    /**
     * @param DescribeSObject $describe
     *
     * @return Metadata
     */
    public function setDescribe(DescribeSObject $describe): Metadata
    {
        $this->describe = $describe;

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return Field|null
     */
    public function describeField(string $fieldName): ?Field
    {
        if (null === $this->describe) {
            return null;
        }

        $fields = $this->describe->getFields();

        foreach ($fields as $field) {
            if ($field->getName() === $fieldName) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param string $propertyName
     *
     * @return Field|null
     */
    public function describeFieldByProperty(string $propertyName): ?Field
    {
        $fieldName = $this->propertyMap->get($propertyName);

        if (null !== $fieldName) {
            return $this->describeField($fieldName);
        }

        return null;
    }
}
