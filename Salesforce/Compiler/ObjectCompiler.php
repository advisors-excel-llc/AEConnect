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
        $this->compiler = $fieldCompiler;
    }

    public function fastCompile(Metadata $classMeta, SObject $SObject)
    {
        $sObjectArray = $SObject->getFields();
        $serializable = [];
        foreach ($classMeta->getFieldMetadata() as $field) {
            /** @var $field FieldMetadata */
            //If we have a custom transformer defined, we won't rely on serialization.
            if ($field->getTransformer() || $field instanceof RecordTypeMetadata) {
                continue;
            }
            if (isset($sObjectArray[$field->getField()])) {
                $serializable[$field->getProperty()] = $sObjectArray[$field->getField()];
            }
        }

        $json           = $this->serializer->serialize($serializable, 'json');
        $entity = $this->serializer->deserialize($json, $classMeta->getClassName(), 'json');
        
        foreach ($classMeta->getFieldMetadata() as $field) {
            if ($field->getTransformer() || $field instanceof RecordTypeMetadata) {
                try {
                    $newValue = $this->fieldCompiler->compileInbound(
                        $record->sObject[$field->getField()],
                        $record->sObject,
                        $field,
                        $record->entity,
                        true
                    );
                    if (null !== $newValue) {
                        $field->setValueForEntity($record->entity, $newValue);
                    }
                } catch (\Throwable $e) {
                    $record->error = $e->getMessage();
                    break;
                }
            }
        }
    }

    public function slowCompile(Metadata $classMeta, SObject $SObject)
    {

    }

}
