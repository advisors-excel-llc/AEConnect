<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/16/19
 * Time: 11:52 AM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\Connection;
use AE\ConnectBundle\Connection\Dbal\ConnectionEntityInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class ConnectionEntity
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="connection")
 */
class ConnectionEntity implements ConnectionEntityInterface
{
    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false, unique=true)
     * @Connection()
     */
    private $name;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int|null $id
     *
     * @return ConnectionEntity
     */
    public function setId(?int $id): ConnectionEntity
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return ConnectionEntity
     */
    public function setName(string $name): ConnectionEntity
    {
        $this->name = $name;

        return $this;
    }
}
