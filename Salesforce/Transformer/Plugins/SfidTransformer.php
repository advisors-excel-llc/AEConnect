<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 2:45 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use AE\ConnectBundle\Salesforce\Transformer\Util\SfidFinder;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Common\Collections\Collection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;

class SfidTransformer extends AbstractTransformerPlugin implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var SfidFinder
     */
    private $sfidFinder;

    public function __construct(
        ManagerRegistry $registry,
        Reader $reader,
        SfidFinder $sfidFinder,
        ?LoggerInterface $logger = null
    ) {
        $this->registry   = $registry;
        $this->reader     = $reader;
        $this->sfidFinder = $sfidFinder;

        AnnotationRegistry::loadAnnotationClass(Connection::class);

        $this->setLogger($logger ?: new NullLogger());
    }

    public function supports(TransformerPayload $payload): bool
    {
        $value = $payload->getValue();

        return $payload->getFieldName() === 'Id'
            && null !== $value
            && (
                ($payload->getDirection() === TransformerPayload::OUTBOUND && !is_string($value))
                || ($payload->getDirection() === TransformerPayload::INBOUND && is_string($value)
                    && $payload->getClassMetadata()->hasAssociation($payload->getPropertyName()))
            );
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
            if (null !== $payload->getEntity()) {
                $id = $payload->getClassMetadata()->getFieldValue(
                    $payload->getEntity(),
                    $payload->getClassMetadata()->getSingleIdentifierFieldName()
                )
                ;
            }
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
        if (!$payload->getClassMetadata()->hasAssociation($payload->getPropertyName())) {
            $payload->setValue($payload->getValue());
            return;
        }

        try {
            // SFID String
            $value = $payload->getValue();
            $association = $payload->getClassMetadata()->getAssociationMapping($payload->getPropertyName());
            $targetClass = $association['targetEntity'];
            /** @var EntityManager $manager */
            $manager = $this->registry->getManagerForClass($targetClass);

            if (!$manager->isOpen()) {
                $manager = EntityManager::create(
                    $manager->getConnection(),
                    $manager->getConfiguration(),
                    $manager->getEventManager()
                );
            }

            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($targetClass);
            $sfid          = $this->sfidFinder->find($value, $targetClass);

            // If no SFID exists in the system, it's time to create one, given we can find a Connection
            if (null === $sfid) {
                $connectionName = $payload->getMetadata()->getConnectionName();

                // Find Connection Field
                $connectionField = 'connection';
                /** @var \ReflectionProperty $property */
                foreach ($classMetadata->getReflectionProperties() as $property) {
                    foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                        if ($annotation instanceof Connection) {
                            $connectionField = $property->getName();
                            break;
                        }
                    }
                }

                $sfid = new $targetClass();

                if (!($sfid instanceof SalesforceIdEntityInterface)) {
                    $sfid = null;
                }

                if (null !== $sfid && $classMetadata->hasField($connectionField)) {
                    $sfid->setConnection($connectionName);
                    $sfid->setSalesforceId($value);

                    $manager->persist($sfid);
                    $manager->flush($sfid);
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
                            $manager->flush($sfid);
                        }
                    }
                }
            }

            $this->assignPayloadValue($sfid, $payload, $association['type'] & ClassMetadataInfo::TO_MANY);
        } catch (\Exception $e) {
            $this->logger->debug('SfidTransformer-001 - '.$e->getMessage());

            $this->assignPayloadValue(
                $sfid,
                $payload,
                !empty($association) && $association['type'] & ClassMetadataInfo::TO_MANY
            );
        }
    }

    private function assignPayloadValue($sfid, TransformerPayload $payload, bool $isAssociation): void
    {
        if (null === $sfid || !$isAssociation) {
            $payload->setValue($sfid);

            return;
        }

        $entity = $payload->getEntity();
        // If there isn't an entity on the payload, then we can't merge with existing.
        // In this case, we'll return what has been found
        if (null === $entity) {
            $payload->setValue(new ArrayCollection([$sfid]));

            return;
        }

        // If there is an entity, process accordingly
        $val = $payload->getFieldMetadata()->getValueFromEntity($entity);
        if ($val instanceof Collection) {
            // Don't add the sfid twice if it already exists
            if ($val->contains($sfid)) {
                $payload->setValue(new ArrayCollection($val->toArray()));

                return;
            }

            $sfids   = $val->toArray();
            $sfids[] = $sfid;
            $payload->setValue(new ArrayCollection($sfids));

            return;
        }

        $payload->setValue(new ArrayCollection([$sfid]));

        return;
    }

    public function getName(): string
    {
        return 'sfid';
    }
}
