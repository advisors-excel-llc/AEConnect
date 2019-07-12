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
    private $id;

    /**
     * @var string
     * @ORM\Column(length=80)
     * @Field("Name")
     */
    private $name;

    /**
     * @var string
     * @ORM\Column(type="guid", length=36, unique=true, nullable=false)
     * @Field("S3F__HCID__c")
     * @ExternalId()
     */
    private $extId;

    /**
     * @var string
     * @ORM\Column(length=18, nullable=true, unique=true)
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
}
