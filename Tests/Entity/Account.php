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
use Ramsey\Uuid\Uuid;

/**
 * Class Account
 * @AEConnect\SObjectType(name="Account", connections={"*"})
 * @ORM\Entity()
 * @ORM\Table("account")
 */
class Account
{
    /**
     * @var int
     * @ORM\Id()
     * @ORM\Column(type="integer", options={"unsigned"=true}, unique=true, nullable=false)
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @AEConnect\Field("S3F__hcid__c", connections={"default"})
     * @AEConnect\Field("AE_Connect_Id__c", connections={"db_test"})
     * @AEConnect\ExternalId()
     * @ORM\Column(type="uuid", nullable=false, unique=true)
     */
    private $extId;

    /**
     * @var string
     * @AEConnect\Field("Name", connections={"*"})
     * @ORM\Column(length=80, nullable=false)
     */
    private $name;

    /**
     * @var array
     * @ORM\Column(type="array")
     * @AEConnect\Field("S3F__Test_Picklist__c", connections={"default"})
     */
    private $testPicklist;

    /**
     * @var string
     * @AEConnect\SalesforceId(connection="*")
     * @ORM\Column(length=18, nullable=true, unique=true)
     */
    private $sfid;

    /**
     * @var string
     * @AEConnect\Connection(connections={"*"})
     */
    private $connection = "default";

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

    /**
     * @return array
     */
    public function getTestPicklist(): array
    {
        return $this->testPicklist;
    }

    /**
     * @param array $testPicklist
     *
     * @return Account
     */
    public function setTestPicklist(array $testPicklist): Account
    {
        $this->testPicklist = $testPicklist;

        return $this;
    }

    /**
     * @return string
     */
    public function getSfid(): ?string
    {
        return $this->sfid;
    }

    /**
     * @param string $sfid
     *
     * @return Account
     */
    public function setSfid(?string $sfid): Account
    {
        $this->sfid = $sfid;

        return $this;
    }

    /**
     * @return string
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * @param string $connection
     *
     * @return Account
     */
    public function setConnection(?string $connection): Account
    {
        $this->connection = $connection;

        return $this;
    }
}
