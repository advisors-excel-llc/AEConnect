<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 1:23 PM
 */

namespace AE\ConnectBundle\Connection\Dbal;

interface ConnectionEntityInterface
{
    public function getName(): string;
    public function setName(string $name);
}
