<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/1/18
 * Time: 10:46 AM
 */

namespace AE\ConnectBundle\Connection;

trait ConnectionsTrait
{
    /**
     * @var array|ConnectionInterface[]
     */
    protected $connections = [];

    /**
     * @return ConnectionInterface[]|array
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * @param ConnectionInterface[]|array $connections
     *
     * @return ConnectionsTrait
     */
    public function setConnections(array $connections): self
    {
        $this->connections = $connections;

        return $this;
    }

    public function addConnection(ConnectionInterface $connection): self
    {
        $index = array_search($connection, $this->connections);

        if ($index === false) {
            $this->connections[] = $connection;
        }

        return $this;
    }

    public function removeConnection(ConnectionInterface $connection): self
    {
        $index = array_search($connection, $this->connections);

        if ($index !== false) {
            unset($this->connections[$index]);
        }

        return $this;
    }
}
