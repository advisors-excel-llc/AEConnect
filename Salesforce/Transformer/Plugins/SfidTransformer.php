<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 2:45 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
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
            $classMetadata = $manager->getClassMetadata($targetClass);
            $repo          = $manager->getRepository($targetClass);
            $sfidField     = 'salesforceId';
            $sfid          = null;

            foreach ($classMetadata->getFieldNames() as $property) {
                foreach ($this->reader->getPropertyAnnotations($property) as $annotation) {
                    if ($annotation instanceof SalesforceId) {
                        $sfidField = $property;
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

            $payload->setValue(null !== $sfid && $association['type'] & ClassMetadataInfo::TO_MANY ? [$sfid] : $sfid);
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
        }
    }
}
