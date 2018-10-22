<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 10:25 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer\Plugins;

interface TransformerPluginInterface
{
    public function supports(TransformerPayload $payload): bool;
    public function transform(TransformerPayload $payload);
}
