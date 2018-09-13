<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 5:59 PM
 */

namespace AE\ConnectBundle\Manager;

use AE\ConnectBundle\Connection\ConnectionInterface;
use Doctrine\Common\Collections\ArrayCollection;

class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var ArrayCollection
     */
    private $connections;

    public function __construct()
    {
        $this->connections = new ArrayCollection();
    }

    /**
     * @param string $name
     * @param ConnectionInterface $connection
     *
     * @return ConnectionManager
     */
    public function registerConnection(string $name, ConnectionInterface $connection): ConnectionManager
    {
        $this->connections->set($name, $connection);

        return $this;
    }

    /**
     * @param null|string $name
     *
     * @return ConnectionInterface|null
     */
    public function getConnection(?string $name = null): ?ConnectionInterface
    {
        if (null === $name) {
            return $this->connections->get('default');
        }

        return $this->connections->get($name);
    }
}
