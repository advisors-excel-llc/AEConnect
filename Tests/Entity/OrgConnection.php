<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 11/6/18
 * Time: 2:31 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Connection\Dbal\AuthCredentialsInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class OrgConnection
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="org_connection")
 */
class OrgConnection implements AuthCredentialsInterface
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
     */
    private $name;

    /**
     * @var string
     * @ORM\Column(length=120, nullable=false, unique=true)
     */
    private $username;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     */
    private $password;

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
     * @return OrgConnection
     */
    public function setId(?int $id): OrgConnection
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
     * @return OrgConnection
     */
    public function setName(string $name): OrgConnection
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @param string $username
     *
     * @return OrgConnection
     */
    public function setUsername(string $username): OrgConnection
    {
        $this->username = $username;

        return $this;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $password
     *
     * @return OrgConnection
     */
    public function setPassword(string $password): OrgConnection
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return self::SOAP;
    }

    public function getClientKey(): ?string
    {
        return null;
    }

    public function getClientSecret(): ?string
    {
        return null;
    }

    public function getLoginUrl(): string
    {
        return 'https://login.salesforce.com/';
    }

    public function isActive(): bool
    {
        return true;
    }
}
