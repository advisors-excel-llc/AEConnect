<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 2:45 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SfidTransformer extends AbstractTransformerPlugin implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /**
     * @var Reader
     */
    private $reader;

    public function __construct(RegistryInterface $registry, Reader $reader, ?LoggerInterface $logger = null)
    {
        $this->registry = $registry;
        $this->reader   = $reader;

        AnnotationRegistry::loadAnnotationClass(SalesforceId::class);
        AnnotationRegistry::loadAnnotationClass(Connection::class);

        $this->setLogger($logger ?: new NullLogger());
    }

    public function supports(TransformerPayload $payload): bool
    {
        return $payload->getFieldName() === 'Id'
        && null !== $payload->getValue()
        && $payload->getDirection() === TransformerPayload::OUTBOUND
            ? !is_string($payload->getValue())
            : is_string($payload->getValue())
            && $payload->getClassMetadata()->hasAssociation($payload->getPropertyName());
    }

    protected function transformOutbound(TransformerPayload $payload)
    {
        $value = $payload->getValue();

        if ($value instanceof SalesforceIdEntityInterface) {
            $payload->setValue($value->getSalesforceId());

            return;
        }

        if (is_array($value) || $value instanceof \ArrayAccess) {
            foreach ($value as $sfid) {
                if ($sfid instanceof SalesforceIdEntityInterface) {
                    $connection = $sfid->getConnection();

                    if ($connection instanceof ConnectionEntityInterface) {
                        $connection = $connection->getName();
                    }

                    if ($payload->getMetadata()->getConnectionName() === $connection) {
                        $payload->setValue($sfid->getSalesforceId());

                        return;
                    }
                }
            }
        }

        $payload->setValue(null);

        $id = null;
        try {
            $id = $payload->getClassMetadata()->getFieldValue(
                $payload->getEntity(),
                $payload->getClassMetadata()->getSingleIdentifierFieldName()
            )
            ;
        } catch (\Exception $e) {
            // Left Blank
        }

        $this->logger->debug(
            'Unable to transform the SFID value for {type} with id {id} on {conn}',
            [
                'type' => $payload->getClassMetadata()->getName(),
                'id'   => $id,
                'conn' => $payload->getMetadata()->getConnectionName(),
            ]
        );
    }

    protected function transformInbound(TransformerPayload $payload)
    {
        try {
            // SFID String
            $value       = $payload->getValue();
            $association = $payload->getClassMetadata()->getAssociationMapping($payload->getPropertyName());
            $targetClass = $association['targetEntity'];
            $manager     = $this->registry->getManagerForClass($targetClass);
            /** @var ClassMetadata $classMetadata */
            $classMetadata   = $manager->getClassMetadata($targetClass);
            $repo            = $manager->getRepository($targetClass);
            $sfidField       = 'salesforceId';
            $connectionField = 'connection';
            $sfid            = null;

            foreach ($classMetadata->getFieldNames() as $property) {
                foreach ($this->reader->getPropertyAnnotations(
                    $classMetadata->getReflectionProperty($property)
                ) as $annotation) {
                    if ($annotation instanceof SalesforceId) {
                        $sfidField = $property;
                    }
                    if ($annotation instanceof Connection) {
                        $connectionField = $property;
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

            // If no SFID exists in the system, it's time to create one, given we can find a Connection
            if (null === $sfid) {
                $connectionName = $payload->getMetadata()->getConnectionName();

                $sfid = new $targetClass();
                if (!($sfid instanceof SalesforceIdEntityInterface)) {
                    $sfid = null;
                }

                if (null !== $sfid && $classMetadata->hasField($connectionField)) {
                    $sfid->setConnection($connectionName);
                    $sfid->setSalesforceId($value);

                    $manager->persist($sfid);
                } elseif (null !== $sfid && $classMetadata->hasAssociation($connectionField)) {
                    $connectionAssoc     = $classMetadata->getAssociationMapping($connectionField);
                    $connectionClass     = $connectionAssoc['targetEntity'];
                    $connectionManager   = $this->registry->getManagerForClass($connectionClass);
                    $connectionClassMeta = $connectionManager->getClassMetadata($connectionClass);
                    $connectionRepo      = $connectionManager->getRepository($connectionClass);

                    if ($connectionAssoc['type'] & ClassMetadataInfo::TO_ONE) {
                        $connection = null;

                        if ($connectionClassMeta->hasField('name')) {
                            $connection = $connectionRepo->findOneBy(
                                [
                                    'name' => $connectionName,
                                ]
                            );
                        } else {
                            foreach ($connectionRepo->findAll() as $conn) {
                                if ($conn instanceof ConnectionEntityInterface
                                    && $conn->getName() === $connectionName
                                ) {
                                    $connection = $conn;
                                    break;
                                }
                            }
                        }

                        if (null === $connection) {
                            $sfid = null;
                        } else {
                            $sfid->setConnection($connection);
                            $sfid->setSalesforceId($value);

                            $manager->persist($sfid);
                        }
                    }
                }
            }

            $payload->setValue(null !== $sfid && $association['type'] & ClassMetadataInfo::TO_MANY ? [$sfid] : $sfid);
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
        }
    }
}
