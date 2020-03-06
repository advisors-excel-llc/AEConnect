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

    public function deserializeSobject(Metadata $classMeta, SObject $sObject, ?object $updateEntity = null)
    {
        $sObjectArray = $sObject->getFields();
        $serializable = [];
        foreach ($classMeta->getActiveFieldMetadata() as $field) {
            /** @var $field FieldMetadata */
            //If we have a custom transformer defined, we won't rely on serialization.
            if ($field->getTransformer()) {
                continue;
            }
            $serializable[$field->getProperty()] = $sObjectArray[$field->getField()];
        }

        $entity = $this->serializer->deserialize(
            $this->serializer->serialize($serializable, 'json'),
            $classMeta->getClassName(),
            'json'
        );

        if ($updateEntity !== null) {
            //We have to over write this updateEntity class with the filled data on our deserialized entity now.
            foreach ($classMeta->getActiveFieldMetadata() as $field) {
                /** @var $field FieldMetadata */
                if ($field->getTransformer()) {
                    continue;
                }
                $field->setValueForEntity($updateEntity, $field->getValueFromEntity($entity));
            }
            //Now we can run the transformers as normal without any additional overhead for syncing.
            $entity = $updateEntity;
        }
        return $entity;
    }

    public function SFIDCompile(Metadata $classMeta, SObject $sObject, object $entity)
    {
        $field = $classMeta->getMetadataForField('Id');

        $newValue = $this->fieldCompiler->compileInbound(
            $sObject->getId(),
            $sObject,
            $field,
            $entity,
            true
        );
        $field->setValueForEntity($entity, $newValue);
    }

    public function runTransformers(Metadata $classMeta, SObject $sObject, object $entity)
    {
        foreach ($classMeta->getActiveFieldMetadata() as $fieldMetadata) {
            if ($fieldMetadata->getTransformer() === null || $fieldMetadata->getTransformer() === ''
                || $fieldMetadata->getTransformer() === 'association'
                || $fieldMetadata->getTransformer() === 'sfid') {
                continue;
            }

            $newValue = $this->fieldCompiler->compileInbound(
                $sObject->getFields()[$fieldMetadata->getField()],
                $sObject,
                $fieldMetadata,
                $entity,
                true
            );

            $fieldMetadata->setValueForEntity($entity, $newValue);
        }
    }

    /**
     * @param Metadata $classMeta
     * @param SObject $sObject
     * @param object $updateEntity
     * @return mixed
     */
    public function fastCompile(Metadata $classMeta, SObject $sObject, ?object $updateEntity = null)
    {
        $entity = $this->deserializeSobject($classMeta, $sObject, $updateEntity);
        foreach ($classMeta->getFieldMetadata() as $field) {
            //Only use the compileInbound command if we absolutely have to
            if ($field->getTransformer() &&
                isset($sObject->getFields()[$field->getField()]) &&
                $sObject->getFields()[$field->getField()] !== null &&
                !($updateEntity !== null && $field->getTransformer() === 'sfid') //Always skip SFID transformer if this is a pre existing entity.
            ) {
                $newValue = $this->fieldCompiler->compileInbound(
                    $sObject->getFields()[$field->getField()],
                    $sObject,
                    $field,
                    $entity,
                    true
                );
                //Ensure we aren't incorrectly overwriting unset values from the Salesforce data
                if (array_key_exists($field->getField(),  $sObject->getFields())) {
                    $field->setValueForEntity($entity, $newValue);
                }
            }
        }

        return $entity;
    }

    /**
     * @param Metadata $classMeta
     * @param SObject $sObject
     * @param object $updateEntity
     * @return mixed
     */
    public function slowCompile(Metadata $classMeta, SObject $sObject, ?object $updateEntity = null)
    {
        //Create a new entity
        $class = $classMeta->getClassName();
        $entity = $updateEntity ?? new $class;
        // Apply the field values from the SObject to the Entity
        foreach ($sObject->getFields() as $field => $value) {
            if (null === ($fieldMetadata = $classMeta->getMetadataForField($field)) || ($field === 'Id' && $updateEntity)) {
                continue;
            }
            $newValue = $this->fieldCompiler->compileInbound(
                $value,
                $sObject,
                $fieldMetadata,
                $entity
            );
            $fieldMetadata->setValueForEntity($entity, $newValue);
        }
        return $entity;
    }
}
