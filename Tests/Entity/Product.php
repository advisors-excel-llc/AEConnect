<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/12/18
 * Time: 2:24 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class Product
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("Product2", connections={"*"})
 * @ORM\Entity()
 * @ORM\Table(name="product")
 * @ORM\HasLifecycleCallbacks()
 */
class Product
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
     * @ORM\Column(length=80, nullable=false)
     * @Field(name="Name", connections={"*"})
     */
    private $name;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     * @Field("IsActive", connections={"*"})
     */
    private $active;

    /**
     * @var string
     * @Field(value="S3F__hcid__c", connections={"default"})
     * @Field(value="AE_Connect_Id__c", connections={"db_test"})
     * @ExternalId()
     * @ORM\Column(type="guid", length=36, nullable=false, unique=true)
     */
    private $extId;

    /**
     * @var SalesforceId[]|Collection
     * @SalesforceId(connection="default")
     * @SalesforceId(connection="db_test")
     * @ORM\ManyToMany(targetEntity="AE\ConnectBundle\Tests\Entity\SalesforceId",
     *     cascade={"persist", "merge", "remove"},
     *     orphanRemoval=true
     *     )
     */
    private $sfids;

    public function __construct()
    {
        $this->sfids = new ArrayCollection();
    }

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
     * @return Product
     */
    public function setId(?int $id): Product
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
     * @return Product
     */
    public function setName(string $name): Product
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     *
     * @return Product
     */
    public function setActive(bool $active): Product
    {
        $this->active = $active;

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
     * @return Product
     */
    public function setExtId(string $extId): Product
    {
        $this->extId = $extId;

        return $this;
    }

    /**
     * @return Collection
     */
    public function getSfids(): Collection
    {
        return $this->sfids;
    }

    /**
     * @param Collection $sfids
     *
     * @return Product
     */
    public function setSfids(Collection $sfids): Product
    {
        $this->sfids = $sfids;

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
