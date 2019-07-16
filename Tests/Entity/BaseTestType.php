<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 7/11/19
 * Time: 5:57 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\ExternalId;
use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\SalesforceId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class BaseTestType
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="test_type")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({
 *     "type1" = "AE\ConnectBundle\Tests\Entity\TestMultiMapType1",
 *     "type2" = "AE\ConnectBundle\Tests\Entity\TestMultiMapType2"
 * })
 */
abstract class BaseTestType
{
    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", unique=true, options={"unsigned"=true}, nullable=false)
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     * @ORM\Column(length=80)
     * @Field("Name", connections={"*"})
     */
    protected $name;

    /**
     * @var string
     * @ORM\Column(type="guid", length=36, unique=true, nullable=false)
     * @Field("S3F__HCID__c", connections={"default"})
     * @ExternalId()
     */
    protected $extId;

    /**
     * @var string
     * @ORM\Column(length=18, nullable=true, unique=true)
     * @SalesforceId(connection="default")
     */
    protected $sfid;

    /**
     * @var BaseTestType|null
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\BaseTestType", inversedBy="children")
     * @Field("S3F__Parent__c", connections={"default"})
     */
    protected $parent;

    /**
     * @var ArrayCollection
     * @ORM\OneToMany(targetEntity="AE\ConnectBundle\Tests\Entity\BaseTestType", mappedBy="parent")
     */
    protected $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
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
     * @return BaseTestType
     */
    public function setId(?int $id): BaseTestType
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
     * @return BaseTestType
     */
    public function setName(string $name): BaseTestType
    {
        $this->name = $name;

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
     * @return BaseTestType
     */
    public function setExtId(string $extId): BaseTestType
    {
        $this->extId = $extId;

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
     * @return BaseTestType
     */
    public function setSfid(string $sfid): BaseTestType
    {
        $this->sfid = $sfid;

        return $this;
    }

    /**
     * @return BaseTestType|null
     */
    public function getParent(): ?BaseTestType
    {
        return $this->parent;
    }

    /**
     * @param BaseTestType|null $parent
     *
     * @return BaseTestType
     */
    public function setParent(?BaseTestType $parent): BaseTestType
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return ArrayCollection
     */
    public function getChildren(): ArrayCollection
    {
        return $this->children;
    }

    /**
     * @param ArrayCollection $children
     *
     * @return BaseTestType
     */
    public function setChildren(ArrayCollection $children): BaseTestType
    {
        $this->children = $children;

        return $this;
    }
}
