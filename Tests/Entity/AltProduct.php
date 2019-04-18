<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 1:37 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class AltProduct
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="alt_product")
 * @SObjectType(name="Product2", connections={"*"})
 */
class AltProduct
{
    /**
     * @var int|null
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var boolean
     * @ORM\Column(type="boolean")
     * @Field("IsActive", connections={"*"})
     */
    private $active;

    /**
     * @var string|null
     * @ORM\Column(length=80, nullable=false)
     * @Field("Name", connections={"*"})
     */
    private $name;

    /**
     * @var ArrayCollection|AltSalesforceId[]|Collection
     * @ORM\ManyToMany(targetEntity="AE\ConnectBundle\Tests\Entity\AltSalesforceId", orphanRemoval=true,
     *     cascade={"all"})
     * @\AE\ConnectBundle\Annotations\SalesforceId(connection="*")
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
     * @return AltProduct
     */
    public function setId(?int $id): AltProduct
    {
        $this->id = $id;

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
     * @return AltProduct
     */
    public function setActive(bool $active): AltProduct
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param null|string $name
     *
     * @return AltProduct
     */
    public function setName(?string $name): AltProduct
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return AltSalesforceId[]|ArrayCollection|Collection
     */
    public function getSfids()
    {
        return $this->sfids;
    }

    /**
     * @param AltSalesforceId[]|ArrayCollection|Collection $sfids
     *
     * @return AltProduct
     */
    public function setSfids($sfids)
    {
        $this->sfids = $sfids;

        return $this;
    }
}
