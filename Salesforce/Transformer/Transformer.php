<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 10:14 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPluginInterface;
use AE\ConnectBundle\Util\PrioritizedCollection;

class Transformer implements TransformerInterface
{
    private $transformers;

    public function __construct()
    {
        $this->transformers = new PrioritizedCollection();
    }

    public function transformInbound(TransformerPayload $payload)
    {
        /** @var TransformerPluginInterface $transformer */
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($payload)) {
                $transformer->transformInbound($payload);
            }
        }
    }

    public function transformOutbound(TransformerPayload $payload)
    {
        /** @var TransformerPluginInterface $transformer */
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($payload)) {
                $transformer->transformOutbound($payload);
            }
        }
    }

    public function registerPlugin(TransformerPluginInterface $transformer, int $priority = 0)
    {
        $this->transformers->add($transformer, $priority);
    }
}
