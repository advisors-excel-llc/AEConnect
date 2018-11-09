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
use AE\SalesforceRestSdk\Model\SObject;
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
     * @var ArrayCollection|FieldMetadata[]
     * @Serializer\Type("ArrayCollection<AE\ConnectBundle\Metadata\FieldMetadata>")
     */
    private $fieldMetadata;

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
     * @var FieldMetadata|null
     * @Serializer\Type("AE\ConnectBundle\Metadata\FieldMetadata")
     */
    private $connectionNameField;

    /**
     * Metadata constructor.
     *
     * @param string $connectionName
     */
    public function __construct(string $connectionName)
    {
        $this->fieldMetadata  = new ArrayCollection();
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
    public function getClassName(): ?string
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
     * @return FieldMetadata[]|ArrayCollection
     */
    public function getFieldMetadata(): ArrayCollection
    {
        return $this->fieldMetadata;
    }

    /**
     * @param FieldMetadata[]|ArrayCollection $fieldMetadata
     *
     * @return Metadata
     */
    public function setFieldMetadata(ArrayCollection $fieldMetadata)
    {
        $this->fieldMetadata = $fieldMetadata;

        return $this;
    }

    public function addFieldMetadata(FieldMetadata $metadata): Metadata
    {
        if (!$this->fieldMetadata->contains($metadata)) {
            $this->fieldMetadata->add($metadata);
        }

        return $this;
    }

    public function removeFieldMetadata(FieldMetadata $metadata): Metadata
    {
        $this->fieldMetadata->removeElement($metadata);

        return $this;
    }

    /**
     * @param string $propertyName
     *
     * @return FieldMetadata|null
     */
    public function getMetadataForProperty(string $propertyName): ?FieldMetadata
    {
        foreach ($this->fieldMetadata as $metadata) {
            if ($metadata->getProperty() === $propertyName) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * @param string $fieldName
     *
     * @return FieldMetadata|null
     */
    public function getMetadataForField(string $fieldName): ?FieldMetadata
    {
        foreach ($this->fieldMetadata as $metadata) {
            if (strtolower($metadata->getField()) === strtolower($fieldName)) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function getPropertyMap(): array
    {
        $map = [];

        foreach ($this->fieldMetadata as $metadata) {
            $map[$metadata->getProperty()] = $metadata->getField();
        }

        return $map;
    }

    public function getFieldMap(): array
    {
        $map = $this->getPropertyMap();

        return array_flip($map);
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
        $map = $this->getPropertyMap();

        return array_key_exists($propertyName, $map) ? $map[$propertyName] : null;
    }

    /**
     * @param string $fieldName
     *
     * @return null|string
     */
    public function getPropertyByField(string $fieldName): ?string
    {
        $map = $this->getFieldMap();

        foreach ($map as $field => $prop) {
            if (strtolower($field) === strtolower($fieldName)) {
                return $prop;
            }
        }

        return null;
    }


    /**
     * @return ArrayCollection|FieldMetadata[]
     */
    public function getIdentifiers(): ArrayCollection
    {
        return $this->fieldMetadata->filter(
            function (FieldMetadata $metadata) {
                return $metadata->isIdentifier();
            }
        );
    }

    /**
     * @param string $propertyName
     *
     * @return bool
     */
    public function isIdentifier(string $propertyName): bool
    {
        return null !== ($meta = $this->getMetadataForProperty($propertyName)) && $meta->isIdentifier();
    }

    /**
     * @return array
     */
    public function getIdentifyingFields(): array
    {
        $props  = $this->getIdentifiers();
        $fields = [];

        foreach ($props as $prop) {
            $fields[$prop->getProperty()] = $prop->getField();
        }

        return $fields;
    }

    /**
     * @param string $fieldName
     *
     * @return bool
     */
    public function isIdentifyingField(string $fieldName): bool
    {
        return null !== ($meta = $this->getMetadataForField($fieldName)) && $meta->isIdentifier();
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
            if (strtolower($field->getName()) === strtolower($fieldName)) {
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
        $fieldName = $this->getFieldByProperty($propertyName);

        if (null !== $fieldName) {
            return $this->describeField($fieldName);
        }

        return null;
    }

    /**
     * @return RecordTypeMetadata|null
     */
    public function getRecordType(): ?RecordTypeMetadata
    {
        return $this->getMetadataForField('RecordTypeId');
    }

    /**
     * @param string $recordTypeName
     *
     * @return null|string
     */
    public function getRecordTypeId(string $recordTypeName)
    {
        foreach ($this->describe->getRecordTypeInfos() as $recordTypeInfo) {
            if ($recordTypeInfo->getDeveloperName() === $recordTypeName
                || $recordTypeInfo->getName() === $recordTypeName) {
                return $recordTypeInfo->getRecordTypeId();
            }
        }

        return null;
    }

    /**
     * @param string $recordTypeId
     *
     * @return null|string
     */
    public function getRecordTypeDeveloperName(string $recordTypeId)
    {
        foreach ($this->describe->getRecordTypeInfos() as $recordTypeInfo) {
            $sysId  = strtolower(substr($recordTypeInfo->getRecordTypeId(), 0, 15));
            $compId = strtolower(substr($recordTypeId, 0, 15));
            if ($sysId === $compId) {
                return $recordTypeInfo->getDeveloperName();
            }
        }

        return null;
    }

    /**
     * @param string $recordTypeId
     *
     * @return null|string
     */
    public function getRecordTypeName(string $recordTypeId)
    {
        foreach ($this->describe->getRecordTypeInfos() as $recordTypeInfo) {
            if ($recordTypeInfo->getRecordTypeId() === $recordTypeId) {
                return $recordTypeInfo->getName();
            }
        }

        return null;
    }

    /**
     * @return FieldMetadata|null
     */
    public function getConnectionNameField(): ?FieldMetadata
    {
        return $this->connectionNameField;
    }

    /**
     * @param FieldMetadata|null $connectionNameField
     *
     * @return Metadata
     */
    public function setConnectionNameField(?FieldMetadata $connectionNameField): Metadata
    {
        $this->connectionNameField = $connectionNameField;

        return $this;
    }
}
