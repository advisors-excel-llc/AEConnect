<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/15/19
 * Time: 1:26 PM
 */

namespace AE\ConnectBundle\Connection\Dbal;

interface SalesforceIdEntityInterface
{
    public function getConnection();
    public function setConnection($connection);
    public function getSalesforceId();
    public function setSalesforceId(?string $sfid);
}
