<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/19/18
 * Time: 12:41 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations\Field;
use AE\ConnectBundle\Annotations\RecordType;
use AE\ConnectBundle\Annotations\SalesforceId;
use AE\ConnectBundle\Annotations\SObjectType;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Class TestObject
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("S3F__Test_Object__c")
 * @ORM\Entity()
 * @ORM\Table("test_object")
 * @ORM\HasLifecycleCallbacks()
 */
class TestObject
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
     * @Field("S3F__hcid__c")
     */
    private $extId;

    /**
     * @var string
     * @ORM\Column(length=18, nullable=true)
     */
    private $recordType;

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
     * @return TestObject
     */
    public function setId(?int $id): TestObject
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
     * @return TestObject
     */
    public function setName(string $name): TestObject
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
     * @return TestObject
     */
    public function setExtId(string $extId): TestObject
    {
        $this->extId = $extId;

        return $this;
    }

    /**
     * @RecordType()
     * @return string
     */
    public function getRecordType(): ?string
    {
        return $this->recordType;
    }

    /**
     * @param string $recordType
     * @RecordType()
     *
     * @return TestObject
     */
    public function setRecordType(?string $recordType): TestObject
    {
        $this->recordType = $recordType;

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
     * @return TestObject
     */
    public function setSfid(?string $sfid): TestObject
    {
        $this->sfid = $sfid;

        return $this;
    }

    /**
     * @throws \Exception
     */
    public function prePersist()
    {
        if (null === $this->extId) {
            $this->extId = Uuid::uuid4()->toString();
        }
    }
}
