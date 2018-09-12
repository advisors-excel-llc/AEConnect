<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:00 PM
 */

namespace AE\ConnectBundle\Connection;

use AE\ConnectBundle\Streaming\ClientInterface;

interface ConnectionInterface
{
    public function getStreamingClient(): ClientInterface;
    public function getRestClient();
}
