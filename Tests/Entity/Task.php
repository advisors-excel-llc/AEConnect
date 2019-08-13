<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 2:23 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class Task
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("Task")
 * @ORM\Entity()
 * @ORM\Table("task")
 * @ORM\HasLifecycleCallbacks()
 */
class Task
{
    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string|null
     * @ORM\Column(length=30, nullable=true)
     * @Field("Subject")
     */
    private $subject;

    /**
     * @var Contact|null
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Contact")
     * @Field("WhoId")
     */
    private $contact;

    /**
     * @var Account|null
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Account")
     * @Field("WhatId")
     */
    private $account;

    /**
     * @var string
     * @ORM\Column(length=8, nullable=false)
     * @Field("Status")
     */
    private $status;

    /**
     * @var string
     * @ORM\Column(length=10)
     * @Field("Priority")
     */
    private $priority = "Normal";

    /**
     * @var string
     * @Field("S3F__hcid__c")
     * @ExternalId()
     * @ORM\Column(type="guid", length=36, nullable=false, unique=true)
     */
    private $extId;

    /**
     * @var string|null
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
     * @return Task
     */
    public function setId(?int $id): Task
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * @param null|string $subject
     *
     * @return Task
     */
    public function setSubject(?string $subject): Task
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @return Contact|null
     */
    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    /**
     * @param Contact|null $contact
     *
     * @return Task
     */
    public function setContact(?Contact $contact): Task
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * @return Account|null
     */
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /**
     * @param Account|null $account
     *
     * @return Task
     */
    public function setAccount(?Account $account): Task
    {
        $this->account = $account;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * @param string $status
     *
     * @return Task
     */
    public function setStatus(?string $status): Task
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return string
     */
    public function getPriority(): string
    {
        return $this->priority;
    }

    /**
     * @param string $priority
     *
     * @return Task
     */
    public function setPriority(string $priority): Task
    {
        $this->priority = $priority;

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
     * @return Task
     */
    public function setExtId(string $extId): Task
    {
        $this->extId = $extId;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSfid(): ?string
    {
        return $this->sfid;
    }

    /**
     * @param null|string $sfid
     *
     * @return Task
     */
    public function setSfid(?string $sfid): Task
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
