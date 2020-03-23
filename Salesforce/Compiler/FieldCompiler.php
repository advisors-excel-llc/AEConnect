<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/2/19
 * Time: 12:40 PM
 */

namespace AE\ConnectBundle\Salesforce\Compiler;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\TransformerInterface;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class FieldCompiler
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var TransformerInterface
     */
    private $transformer;

    public function __construct(ManagerRegistry $registry, TransformerInterface $transformer)
    {
        $this->registry    = $registry;
        $this->transformer = $transformer;
    }

    /**
     * @param $value
     * @param SObject $object
     * @param FieldMetadata $fieldMetadata
     * @param Mixed $entity
     *
     * @return mixed
     */
    public function compileInbound($value, SObject $object, FieldMetadata $fieldMetadata, $entity = null, $isBulk = false)
    {
        $metadata  = $fieldMetadata->getMetadata();
        $className = $fieldMetadata->getMetadata()->getClassName();

        if (null === $className) {
            throw new \RuntimeException("No class found in metadata");
        }

        /** @var EntityManagerInterface $manager */
        $manager       = $this->registry->getManagerForClass($className);
        $classMetadata = $manager->getClassMetadata($className);
        $field         = $fieldMetadata->getField();

        $payload = TransformerPayload::inbound()
                                     ->setClassMetadata($classMetadata)
                                     ->setEntity($entity)
                                     ->setSObject($object)
                                     ->setMetadata($metadata)
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setFieldName($field)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setValue(is_string($value) && strlen($value) === 0 ? null : $value)
                                    ->setIsBulk($isBulk)
        ;

        $this->transformer->transform($payload);

        return $payload->getValue();
    }

    /**
     * @param $value
     * @param $entity
     * @param FieldMetadata $fieldMetadata
     * @param SObject $sobject
     *
     * @return mixed
     */
    public function compileOutbound($value, $entity, FieldMetadata $fieldMetadata, ?SObject $sobject = null)
    {
        $metadata  = $fieldMetadata->getMetadata();
        $className = $fieldMetadata->getMetadata()->getClassName();

        if (null === $className) {
            throw new \RuntimeException("No class found in metadata");
        }
        /** @var EntityManagerInterface $manager */
        $manager       = $this->registry->getManagerForClass($className);
        $classMetadata = $manager->getClassMetadata($className);
        $payload       = TransformerPayload::outbound()
                                           ->setValue($value)
                                           ->setPropertyName($fieldMetadata->getProperty())
                                           ->setFieldName($fieldMetadata->getField())
                                           ->setFieldMetadata($fieldMetadata)
                                           ->setEntity($entity)
                                           ->setSObject($sobject)
                                           ->setMetadata($metadata)
                                           ->setClassMetadata($classMetadata)
        ;
        $this->transformer->transform($payload);

        return $payload->getValue();
    }
}
