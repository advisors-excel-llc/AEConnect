<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 1/16/19
 * Time: 11:52 AM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Annotations as AEConnect;
use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class SalesforceId
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="salesforce_id")
 */
class SalesforceId implements SalesforceIdEntityInterface
{
    /**
     * @var int|null
     * @ORM\Id()
     * @ORM\Column(type="integer", nullable=false, unique=true, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var ConnectionEntity|null
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\ConnectionEntity")
     * @AEConnect\Connection()
     */
    private $connection;

    /**
     * @var string|null
     * @ORM\Column(length=18, unique=true, nullable=true)
     * @AEConnect\SalesforceId()
     */
    private $salesforceId;

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
     * @return SalesforceId
     */
    public function setId(?int $id): SalesforceId
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return ConnectionEntity|null
     */
    public function getConnection(): ?ConnectionEntity
    {
        return $this->connection;
    }

    /**
     * @param ConnectionEntity|null $connection
     *
     * @return SalesforceId
     */
    public function setConnection($connection): SalesforceId
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSalesforceId(): ?string
    {
        return $this->salesforceId;
    }

    /**
     * @param null|string $salesforceId
     *
     * @return SalesforceId
     */
    public function setSalesforceId(?string $salesforceId): SalesforceId
    {
        $this->salesforceId = $salesforceId;

        return $this;
    }
}
