<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 10:37 AM
 */

namespace AE\ConnectBundle\Connection;

trait ConnectionTrait
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param Connection $connection
     *
     * @return ConnectionTrait
     */
    public function setConnection(Connection $connection): ConnectionTrait
    {
        $this->connection = $connection;

        return $this;
    }
}
