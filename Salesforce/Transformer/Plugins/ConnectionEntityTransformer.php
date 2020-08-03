<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 1:46 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Salesforce\Transformer\Util\ConnectionFinder;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConnectionEntityTransformer extends AbstractTransformerPlugin implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionFinder
     */
    private $connectionFinder;

    public function __construct(
        ConnectionFinder $connectionFinder,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionFinder = $connectionFinder;
        $this->setLogger($logger ?: new NullLogger());
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
            $value       = $payload->getValue();
            $connection  = $this->connectionFinder->find($value, $payload->getMetadata());
            $association = $payload->getClassMetadata()->getAssociationMapping($payload->getPropertyName());

            // Set the payload value. If the $connection is null, no connection entity was found and that is ok
            if (null !== $connection && $association['type'] & ClassMetadataInfo::TO_MANY) {
                $entity = $payload->getEntity();
                $values = null !== $entity
                    ? $payload->getFieldMetadata()->getValueFromEntity($entity)
                    : new ArrayCollection();

                if (null === $values) {
                    $values = new ArrayCollection();
                }

                if ($values instanceof Collection && !$values->contains($connection)) {
                    $values->add($connection);
                }

                $payload->setValue($values);
            } else {
                $payload->setValue($connection);
            }
        } catch (MappingException $e) {
            $this->logger->warning($e->getMessage());
            $this->logger->debug('ConnectionEntityTransformer-001 - '.$e->getTraceAsString());
        }
    }
}
