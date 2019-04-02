<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/2/19
 * Time: 12:40 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound\Compiler;

use AE\ConnectBundle\Metadata\FieldMetadata;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\TransformerInterface;
use AE\SalesforceRestSdk\Model\SObject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class FieldCompiler
{
    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var TransformerInterface
     */
    private $transformer;

    public function __construct(RegistryInterface $registry, TransformerInterface $transformer)
    {
        $this->registry    = $registry;
        $this->transformer = $transformer;
    }

    /**
     * @param $value
     * @param SObject $object
     * @param FieldMetadata $fieldMetadata
     *
     * @return mixed
     */
    public function compile($value, SObject $object, FieldMetadata $fieldMetadata)
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
                                     ->setEntity($object)
                                     ->setMetadata($metadata)
                                     ->setFieldMetadata($fieldMetadata)
                                     ->setFieldName($field)
                                     ->setPropertyName($fieldMetadata->getProperty())
                                     ->setValue(is_string($value) && strlen($value) === 0 ? null : $value)
        ;

        $this->transformer->transform($payload);

        return $payload->getValue();
    }
}
