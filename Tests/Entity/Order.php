<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 2:28 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class Order
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("Order")
 * @ORM\Entity()
 * @ORM\Table(name="order_table")
 * @ORM\HasLifecycleCallbacks()
 */
class Order
{
    public const DRAFT     = "Draft";
    public const ACTIVATED = "Activated";

    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Account
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Account")
     * @Field(name="AccountId")
     */
    private $account;

    /**
     * @var Contact
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Contact")
     * @Field(name="ShipToContactId")
     */
    private $shipToContact;

    /**
     * @var \DateTime
     * @ORM\Column(type="datetime", nullable=false)
     * @Field("EffectiveDate")
     */
    private $effectiveDate;

    /**
     * @var string
     * @ORM\Column(length=20, nullable=false)
     * @Field("Status")
     */
    private $status;

    /**
     * @var string
     * @Field("S3F__hcid__c")
     * @ExternalId()
     * @ORM\Column(type="guid", length=36, nullable=false, unique=true)
     */
    private $extId;

    /**
     * @var AltSalesforceId
     * @SalesforceId()
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\AltSalesforceId", cascade={"all"})
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
     * @return Order
     */
    public function setId(?int $id): Order
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return Account
     */
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /**
     * @param Account $account
     *
     * @return Order
     */
    public function setAccount(?Account $account): Order
    {
        $this->account = $account;

        return $this;
    }

    /**
     * @return Contact
     */
    public function getShipToContact(): ?Contact
    {
        return $this->shipToContact;
    }

    /**
     * @param Contact $shipToContact
     *
     * @return Order
     */
    public function setShipToContact(?Contact $shipToContact): Order
    {
        $this->shipToContact = $shipToContact;

        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getEffectiveDate(): \DateTime
    {
        return $this->effectiveDate;
    }

    /**
     * @param \DateTime $effectiveDate
     *
     * @return Order
     */
    public function setEffectiveDate(\DateTime $effectiveDate): Order
    {
        $this->effectiveDate = $effectiveDate;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     *
     * @return Order
     */
    public function setStatus(string $status): Order
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return string
     */
    public function getExtId(): ?string
    {
        return $this->extId;
    }

    /**
     * @param string $extId
     *
     * @return Order
     */
    public function setExtId(string $extId): Order
    {
        $this->extId = $extId;

        return $this;
    }

    /**
     * @return AltSalesforceId
     */
    public function getSfid(): ?AltSalesforceId
    {
        return $this->sfid;
    }

    /**
     * @param AltSalesforceId $sfid
     *
     * @return Order
     */
    public function setSfid(AltSalesforceId $sfid): Order
    {
        $this->sfid = $sfid;

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
