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

/**
 * Class Metadata
 *
 * @package AE\ConnectBundle\Metadata
 */
class Metadata
{
    /**
     * @var string
     */
    private $className;

    /**
     * @var string
     */
    private $sObjectType;

    /**
     * @var ArrayCollection<string, string>
     */
    private $fieldMap;

    /**
     * @var ArrayCollection<string>
     */
    private $identifiers;

    /** @var DescribeSObject */
    private $describe;

    /**
     * @var string
     */
    private $connectionName;

    /**
     * Metadata constructor.
     *
     * @param string $connectionName
     */
    public function __construct(string $connectionName)
    {
        $this->fieldMap       = new ArrayCollection();
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
    public function getFieldMap(): ArrayCollection
    {
        return $this->fieldMap;
    }

    /**
     * @param ArrayCollection $fieldMap
     *
     * @return Metadata
     */
    public function setFieldMap(ArrayCollection $fieldMap): Metadata
    {
        $this->fieldMap = $fieldMap;

        return $this;
    }

    /**
     * @param string $propertyName
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function addField(string $propertyName, string $fieldName): Metadata
    {
        $this->fieldMap->set($propertyName, $fieldName);

        return $this;
    }

    /**
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function removeField(string $fieldName): Metadata
    {
        $this->fieldMap->removeElement($fieldName);

        return $this;
    }

    /**
     * @param string $propertyName
     *
     * @return null|string
     */
    public function getFieldByProperty(string $propertyName): ?string
    {
        return $this->fieldMap->get($propertyName);
    }

    /**
     * @param string $fieldName
     *
     * @return null|string
     */
    public function getPropertyByField(string $fieldName): ?string
    {
        $prop = $this->fieldMap->indexOf($fieldName);

        return false === $prop ? null : $prop;
    }

    /**
     * @param string $propertyName
     *
     * @return Metadata
     */
    public function removeProperty(string $propertyName): Metadata
    {
        $this->fieldMap->remove($propertyName);

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
     * @param string $fieldName
     *
     * @return Metadata
     */
    public function addIdentifyingField(string $fieldName): Metadata
    {
        $propertyName = $this->fieldMap->indexOf($fieldName);

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
        $propertyName = $this->fieldMap->indexOf($fieldName);

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
        $propertyName = $this->fieldMap->indexOf($fieldName);

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
        $fieldName = $this->fieldMap->get($propertyName);

        if (null !== $fieldName) {
            return $this->describeField($fieldName);
        }

        return null;
    }
}
