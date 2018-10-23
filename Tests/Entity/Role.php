<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 7:10 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class Role
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table("role")
 * @ORM\HasLifecycleCallbacks()
 * @SObjectType("UserRole")
 */
class Role
{
    /**
     * @var int|null
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Field("Name")
     */
    private $name;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false, unique=true)
     * @Field("DeveloperName")
     * @ExternalId()
     */
    private $developerName;

    /**
     * @var string
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @SalesforceId()
     */
    private $sfid;

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
     * @return Role
     */
    public function setId(?int $id): Role
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
     * @return Role
     */
    public function setName(string $name): Role
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getDeveloperName(): string
    {
        return $this->developerName;
    }

    /**
     * @param string $developerName
     *
     * @return Role
     */
    public function setDeveloperName(string $developerName): Role
    {
        $this->developerName = $developerName;

        return $this;
    }

    /**
     * @return string
     */
    public function getSfid(): string
    {
        return $this->sfid;
    }

    /**
     * @param string $sfid
     *
     * @return Role
     */
    public function setSfid(string $sfid): Role
    {
        $this->sfid = $sfid;

        return $this;
    }
}
