<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 6:20 PM
 */

namespace AE\ConnectBundle\Manager;

use AE\ConnectBundle\Connection\ConnectionInterface;

interface ConnectionManagerInterface
{
    public function registerConnection(string $name, ConnectionInterface $connection);
    public function getConnection(?string $name): ?ConnectionInterface;
}
