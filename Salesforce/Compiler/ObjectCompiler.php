<?php

namespace AE\ConnectBundle\Salesforce\Compiler;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Metadata\Metadata;
use AE\ConnectBundle\Metadata\RecordTypeMetadata;
use AE\SalesforceRestSdk\Model\SObject;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\SerializerInterface;

class ObjectCompiler
{
    /** @var SerializerInterface  */
    private $serializer;
    /** @var FieldCompiler */
    private $fieldCompiler;

    /**
     * ObjectCompiler constructor.
     * @param FieldCompiler $compiler
     * @param SerializerInterface $serializer
     */
    public function __construct(FieldCompiler $fieldCompiler, SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
        $this->fieldCompiler = $fieldCompiler;
    }

    /**
     * @param Metadata $classMeta
     * @param SObject $sObject
     * @return mixed
     */
    public function fastCompile(Metadata $classMeta, SObject $sObject)
    {
        $sObjectArray = $sObject->getFields();
        $serializable = [];
        foreach ($classMeta->getFieldMetadata() as $field) {
            /** @var $field FieldMetadata */
            //If we have a custom transformer defined, we won't rely on serialization.
            if ($field->getTransformer()) {
                continue;
            }
            if (isset($sObjectArray[$field->getField()])) {
                $serializable[$field->getProperty()] = $sObjectArray[$field->getField()];
            }
        }

        $json   = $this->serializer->serialize($serializable, 'json');
        $entity = $this->serializer->deserialize($json, $classMeta->getClassName(), 'json');

        foreach ($classMeta->getFieldMetadata() as $field) {
            //Only use the compileInbound command if we absolutely have to
            if ($field->getTransformer() && isset($sObject->getFields()[$field->getField()]) && $sObject->getFields()[$field->getField()] !== null) {
                $newValue = $this->fieldCompiler->compileInbound(
                    $sObject->getFields()[$field->getField()],
                    $sObject,
                    $field,
                    $entity,
                    true
                );
                if (null !== $newValue) {
                    $field->setValueForEntity($entity, $newValue);
                }
            }
        }

        return $entity;
    }

    /**
     * @param Metadata $classMeta
     * @param SObject $sObject
     * @return mixed
     */
    public function slowCompile(Metadata $classMeta, SObject $sObject)
    {
        //Create a new entity
        $class = $classMeta->getClassName();
        $entity = new $class;
        // Apply the field values from the SObject to the Entity
        foreach ($sObject->getFields() as $field => $value) {
            if (null === ($fieldMetadata = $classMeta->getMetadataForField($field))) {
                continue;
            }
            $newValue = $this->fieldCompiler->compileInbound(
                $value,
                $sObject,
                $fieldMetadata,
                $entity
            );
            if (null !== $newValue) {
                $fieldMetadata->setValueForEntity($entity, $newValue);
            }
        }
        return $entity;
    }
}
