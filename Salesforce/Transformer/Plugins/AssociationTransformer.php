<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/4/18
 * Time: 4:17 PM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

use AE\ConnectBundle\Manager\ConnectionManagerInterface;
use AE\ConnectBundle\Salesforce\Outbound\ReferencePlaceholder;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AssociationTransformer extends AbstractTransformerPlugin
{
    use LoggerAwareTrait;
    /**
     * @var ConnectionManagerInterface
     */
    private $connectionManager;

    /**
     * @var RegistryInterface
     */
    private $managerRegistry;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    public function __construct(
        ConnectionManagerInterface $connectionManager,
        RegistryInterface $managerRegistry,
        ValidatorInterface $validator,
        ?LoggerInterface $logger = null
    ) {
        $this->connectionManager  = $connectionManager;
        $this->managerRegistry    = $managerRegistry;
        $this->validator          = $validator;

        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @param TransformerPayload $payload
     *
     * @return bool
     */
    public function supports(TransformerPayload $payload): bool
    {
        if (null === $payload->getValue()) {
            return false;
        }

        $connection = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());

        if (null === $connection) {
            return false;
        }

        $classMetadata = $payload->getClassMetadata();

        // If the field is not an association field, skip the forthcoming mapping exception
        if (!$classMetadata->hasAssociation($payload->getPropertyName())) {
            return false;
        }

        try {
            $association = $classMetadata->getAssociationMapping($payload->getPropertyName());
            $className   = $association['targetEntity'];
            $metadata    = $connection->getMetadataRegistry()->findMetadataByClass($className);

            if (null === $metadata
                || !$association['isOwningSide']
                || $association['type'] & ClassMetadataInfo::TO_MANY) {
                return false;
            }

            $sfidProperty = $metadata->getIdFieldProperty();

            if (null === $sfidProperty) {
                return false;
            }

            return true;
        } catch (MappingException $e) {
            $this->logger->error(
                '{msg}'.PHP_EOL.'{trace}',
                [
                    'msg'   => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws MappingException
     */
    protected function transformInbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);
        $sfidProperty  = $metadata->getIdFieldProperty();
        /** @var EntityManager $manager */
        $manager = $this->managerRegistry->getManagerForClass($className);
        $repo    = $manager->getRepository($className);

        $entity = $repo->findOneBy([$sfidProperty => $payload->getValue()]);

        $payload->setValue($entity);
    }

    /**
     * @param TransformerPayload $payload
     *
     * @throws MappingException
     */
    protected function transformOutbound(TransformerPayload $payload)
    {
        $connection    = $this->connectionManager->getConnection($payload->getMetadata()->getConnectionName());
        $classMetadata = $payload->getClassMetadata();
        $association   = $classMetadata->getAssociationMapping($payload->getPropertyName());
        $className     = $association['targetEntity'];
        $metadata      = $connection->getMetadataRegistry()->findMetadataByClass($className);
        $sfidProperty  = $metadata->getIdFieldProperty();
        /** @var EntityManager $manager */
        $manager            = $this->managerRegistry->getManagerForClass($className);
        $associatedMetadata = $manager->getClassMetadata($className);
        $entity             = $payload->getValue();
        $sfid               = $associatedMetadata->getFieldValue($entity, $sfidProperty);

        if (null === $sfid) {
            $groups   = [
                'ae_connect_outbound',
                'ae_connect_outbound.'.$connection->getName(),
            ];

            if ($connection->isDefault() && 'default' != $connection->getName()) {
                $groups[] = 'ae_connect_outbound.default';
            }

            $messages = $this->validator->validate(
                $entity,
                null,
                $groups
            );

            if (count($messages) === 0) {
                $assocRefId = spl_object_hash($entity);
                $sfid       = new ReferencePlaceholder($assocRefId);
            }
        }

        $payload->setValue($sfid);
    }

}
