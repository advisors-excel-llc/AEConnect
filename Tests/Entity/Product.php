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
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class Product
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("Product2")
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
     * @Field(name="Name")
     */
    private $name;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     * @Field(name="IsActive")
     */
    private $active;

    /**
     * @var string
     * @Field(value="S3F__hcid__c")
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
     * @return null|string
     */
    public function getSfid(): ?string
    {
        return $this->sfid;
    }

    /**
     * @param null|string $sfid
     *
     * @return Product
     */
    public function setSfid(?string $sfid): Product
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
