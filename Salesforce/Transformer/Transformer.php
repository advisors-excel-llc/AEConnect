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
    private $transformersByName = [];

    public function __construct()
    {
        $this->transformers = new PrioritizedCollection();
    }

    public function transform(TransformerPayload $payload)
    {
        if (isset($this->transformersByName[$payload->getFieldMetadata()->getTransformer()])) {
            //Fast track, if a field metadata has a transformer selected AEConnect/Field("FieldName", transformer="name")
            //so we can skip out on checking supports and just run the transform.
            $this->transformersByName[$payload->getFieldMetadata()->getTransformer()]->transform($payload);
            return;
        }
        /** @var TransformerPluginInterface $transformer */
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($payload)) {
                $transformer->transform($payload);
            }
        }
    }

    public function registerPlugin(TransformerPluginInterface $transformer, int $priority = 0)
    {
        $this->transformers->add($transformer, $priority);
        if ($transformer->getName()) {
            $this->transformersByName[$transformer->getName()] = $transformer;
        }
    }
}
