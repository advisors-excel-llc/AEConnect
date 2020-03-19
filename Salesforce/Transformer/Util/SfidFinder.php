<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/18/19
 * Time: 10:22 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Util;

use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;

class SfidFinder
{
    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    public function __construct(Reader $reader, ManagerRegistry $registry)
    {
        $this->reader   = $reader;
        $this->registry = $registry;

        AnnotationRegistry::loadAnnotationClass(SalesforceId::class);
    }

    /**
     * @param $value
     * @param string $sfidClass
     *
     * @return SalesforceIdEntityInterface|null
     */
    public function find($value, string $sfidClass): ?SalesforceIdEntityInterface
    {
        $manager     = $this->registry->getManagerForClass($sfidClass);
        /** @var ClassMetadata $classMetadata */
        $classMetadata   = $manager->getClassMetadata($sfidClass);
        $repo            = $manager->getRepository($sfidClass);
        $sfidField       = 'salesforceId';
        $sfid            = null;

        /** @var \ReflectionProperty $property */
        foreach ($classMetadata->getReflectionProperties() as $property) {
            foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                if ($annotation instanceof SalesforceId) {
                    $sfidField = $property->getName();
                    break;
                }
            }
        }

        if ($classMetadata->hasField($sfidField)) {
            $sfid = $repo->findOneBy([$sfidField => $value]);
        } else {
            foreach ($repo->findAll() as $item) {
                if ($item instanceof SalesforceIdEntityInterface && $item->getSalesforceId() === $value) {
                    $sfid = $item;
                    break;
                }
            }
        }

        return $sfid;
    }
}
