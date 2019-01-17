<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 1:46 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ConnectionEntityTransformer extends AbstractTransformerPlugin implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var RegistryInterface
     */
    private $registry;

    /** @var Reader */
    private $reader;

    public function __construct(RegistryInterface $registry, Reader $reader, ?LoggerInterface $logger = null)
    {
        $this->registry = $registry;
        $this->reader   = $reader;
        $this->setLogger($logger ?: new NullLogger());

        AnnotationRegistry::loadAnnotationClass(Connection::class);
    }

    protected function supportsInbound(TransformerPayload $payload): bool
    {
        $metadata            = $payload->getMetadata();
        $classMetadata       = $payload->getClassMetadata();
        $fieldMetadata       = $payload->getFieldMetadata();
        $property            = $payload->getPropertyName();
        $connectionNameField = $metadata->getConnectionNameField();

        return null !== $connectionNameField
            && $connectionNameField->getProperty() === $fieldMetadata->getProperty()
            && $classMetadata->hasAssociation($property);
    }

    protected function transformInbound(TransformerPayload $payload)
    {
        try {
            $value           = $payload->getValue();
            $association     = $payload->getClassMetadata()->getAssociationMapping($payload->getPropertyName());
            $connectionClass = $association['targetEntity'];
            $manager         = $this->registry->getManagerForClass($connectionClass);
            $repo            = $manager->getRepository($connectionClass);
            /** @var ClassMetadata $classMetadata */
            $classMetadata   = $manager->getClassMetadata($connectionClass);
            $connectionField = 'connection';
            $connection      = null;

            // Look for fields on the target entity that have the Connection annotation
            foreach ($classMetadata->getFieldNames() as $field) {
                foreach ($this->reader->getPropertyAnnotations(
                    $classMetadata->getReflectionProperty($field)
                ) as $annotation) {
                    if ($annotation instanceof Connection) {
                        $connectionField = $field;
                        break;
                    }
                }
            }

            if ($classMetadata->hasField($connectionField)) {
                // If the entity has a field named 'connection' or uses the Connection annotation on the connection
                // name field,then we can easily do a lookup
                /** @var ConnectionEntityInterface $connection */
                $connection = $repo->findOneBy([$connectionField => $value]);
            } else {
                // If we can't easily determine which field uses the connection name, we have to look at all entities
                $connections = $repo->findAll();
                foreach ($connections as $conn) {
                    if ($conn instanceof ConnectionEntityInterface && $conn->getName() === $value) {
                        $connection = $conn;
                        break;
                    }
                }
            }

            // Set the payload value. If the $connection is null, no connection entity was found and that is ok
            $payload->setValue(
                null !== $connection && $association['type'] & ClassMetadataInfo::TO_MANY
                    ? new ArrayCollection([$connection])
                    : $connection
            );

        } catch (MappingException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug($e->getTraceAsString());
        }
    }
}
