<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/2/18
 * Time: 1:34 PM
 */
namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations as AEConnect;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class Account
 * @AEConnect\SObjectType(value="Account")
 * @ORM\Entity()
 * @ORM\Table("account")
 */
class Account
{
    /**
     * @var int
     * @ORM\Id()
     * @ORM\Column(type="int", options={"unsigned"=true}, unique=true, nullable=false)
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @AEConnect\Field(value="hcid__c", identifier=true)
     * @ORM\Column(type="guid", length=36, nullable=false)
     */
    private $extId;

    /**
     * @var string
     * @AEConnect\Field(value="name", required=true)
     * @ORM\Column(length=80, nullable=false)
     */
    private $name;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     *
     * @return Account
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getExtId(): string
    {
        return $this->extId;
    }

    /**
     * @param string $extId
     *
     * @return Account
     */
    public function setExtId(string $extId): Account
    {
        $this->extId = $extId;

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
     * @return Account
     */
    public function setName(string $name): Account
    {
        $this->name = $name;

        return $this;
    }
}
