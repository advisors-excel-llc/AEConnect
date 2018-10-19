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

    abstract protected function supportsInbound(TransformerPayload $payload): bool;
    abstract protected function supportsOutbound(TransformerPayload $payload): bool;
    abstract protected function transformInbound(TransformerPayload $payload);
    abstract protected function transformOutbound(TransformerPayload $payload);
}
