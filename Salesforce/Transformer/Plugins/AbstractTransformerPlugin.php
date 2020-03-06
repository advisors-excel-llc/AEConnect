<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 11:36 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

abstract class AbstractTransformerPlugin implements TransformerPluginInterface
{
    public function supports(TransformerPayload $payload): bool
    {
        if ($payload->getDirection() === TransformerPayload::OUTBOUND) {
            return $this->supportsOutbound($payload);
        } elseif ($payload->getDirection() === TransformerPayload::INBOUND) {
            return $this->supportsInbound($payload);
        }

        return false;
    }

    public function transform(TransformerPayload $payload)
    {
        if ($payload->getDirection() === TransformerPayload::INBOUND) {
            $this->transformInbound($payload);
        } elseif ($payload->getDirection() === TransformerPayload::OUTBOUND) {
            $this->transformOutbound($payload);
        }
    }

    protected function supportsInbound(TransformerPayload $payload): bool
    {
        return false;
    }

    protected function supportsOutbound(TransformerPayload $payload): bool
    {
        return false;
    }

    protected function transformInbound(TransformerPayload $payload)
    {
        // implement body
    }

    protected function transformOutbound(TransformerPayload $payload)
    {
        // implement body
    }

    /**
     * This is optional, but if you want to use an annotation to choose your transformer, you must provide a name for your transformer.
     * Otherwise we will try to rely on the serializer to preform deserializations, or go into the slow motion path and transform based on supports
     * @return string
     */
    public function getName(): string {
        return '';
    }
}
