<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 2:22 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class Contact
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("Contact")
 * @ORM\Entity()
 * @ORM\Table("contact")
 * @ORM\HasLifecycleCallbacks()
 */
class Contact
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
     * @ORM\Column(length=80, nullable=true)
     * @Field(name="FirstName")
     */
    private $firstName;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     * @Field(name="LastName")
     */
    private $lastName;

    /**
     * @var Account
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Account")
     * @Field("AccountId")
     */
    private $account;

    /**
     * @var string
     * @ORM\Column(type="guid", unique=true, nullable=false)
     * @ExternalId()
     * @Field("hcid__c")
     */
    private $extId;

    /**
     * @var string
     * @SalesforceId()
     * @ORM\Column(length=18, nullable=true, unique=true)
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
     * @return Contact
     */
    public function setId(?int $id): Contact
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * @param string $firstName
     *
     * @return Contact
     */
    public function setFirstName(string $firstName): Contact
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * @return string
     */
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * @param string $lastName
     *
     * @return Contact
     */
    public function setLastName(string $lastName): Contact
    {
        $this->lastName = $lastName;

        return $this;
    }

    /**
     * @return Account
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * @param Account $account
     *
     * @return Contact
     */
    public function setAccount(Account $account): Contact
    {
        $this->account = $account;

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
     * @return Contact
     */
    public function setSfid(string $sfid): Contact
    {
        $this->sfid = $sfid;

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
     * @return Contact
     */
    public function setExtId(string $extId): Contact
    {
        $this->extId = $extId;

        return $this;
    }

    /**
     * @throws \Exception
     * @ORM\PrePersist()
     */
    public function prePersist()
    {
        if (null === $this->extId) {
            $this->extId = Uuid::uuid4()->toString();
        }
    }
}
