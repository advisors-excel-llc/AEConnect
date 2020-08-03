<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/23/19
 * Time: 10:18 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Util;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Metadata\Metadata;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\Persistence\ManagerRegistry;

class ConnectionFinder implements LoggerAwareInterface
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

    public function __construct(ManagerRegistry $registry, Reader $reader, ?LoggerInterface $logger = null)
    {
        $this->registry = $registry;
        $this->reader   = $reader;

        $this->setLogger($logger ?: new NullLogger());
        AnnotationRegistry::loadAnnotationClass(Connection::class);
    }

    public function find(string $connectionName, Metadata $metadata): ?ConnectionEntityInterface
    {
        try {
            if (null === ($fieldMetadata = $metadata->getConnectionNameField())) {
                return null;
            }

            $class   = $metadata->getClassName();
            $prop    = $fieldMetadata->getProperty();
            $manager = $this->registry->getManagerForClass($class);
            /** @var ClassMetadata $classMetadata */
            $classMetadata = $manager->getClassMetadata($class);
            $assoc         = $classMetadata->getAssociationMapping($prop);
            $connClass     = $assoc['targetEntity'];
            $connManager   = $this->registry->getManagerForClass($connClass);
            $repo          = $connManager->getRepository($connClass);
            /** @var ClassMetadata $connMetadata */
            $connMetadata    = $connManager->getClassMetadata($connClass);
            $connectionField = 'connection';
            $connection      = null;

            // Look for fields on the target entity that have the Connection annotation
            /** @var \ReflectionProperty $field */
            foreach ($connMetadata->getReflectionProperties() as $field) {
                foreach ($this->reader->getPropertyAnnotations($field) as $annotation) {
                    if ($annotation instanceof Connection) {
                        $connectionField = $field->getName();
                        break;
                    }
                }
            }

            if ($connMetadata->hasField($connectionField)) {
                // If the entity has a field named 'connection' or uses the Connection annotation on the connection
                // name field,then we can easily do a lookup
                /** @var ConnectionEntityInterface $connection */
                $connection = $repo->findOneBy([$connectionField => $connectionName]);
            } else {
                // If we can't easily determine which field uses the connection name, we have to look at all entities
                $connections = $repo->findAll();
                foreach ($connections as $conn) {
                    if ($conn instanceof ConnectionEntityInterface && $conn->getName() === $connectionName) {
                        $connection = $conn;
                        break;
                    }
                }
            }

            return $connection;
        } catch (ORMException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug('#CF1 ORM Exception in find. '.$e->getTraceAsString());

            return null;
        }
    }
}
