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
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param ConnectionInterface $connection
     *
     * @return ConnectionTrait
     */
    public function setConnection(ConnectionInterface $connection): ConnectionTrait
    {
        $this->connection = $connection;

        return $this;
    }
}
