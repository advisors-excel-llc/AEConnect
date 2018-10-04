<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/3/18
 * Time: 10:15 AM
 */

namespace AE\ConnectBundle\Salesforce\Transformer;

use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPayload;
use AE\ConnectBundle\Salesforce\Transformer\Plugins\TransformerPluginInterface;

interface TransformerInterface
{
    public function transformInbound(TransformerPayload $payload);
    public function transformOutbound(TransformerPayload $payload);
    public function registerPlugin(TransformerPluginInterface $transformer, int $priority = 0);
}
