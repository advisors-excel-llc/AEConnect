<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 4/18/19
 * Time: 1:32 PM
 */

namespace AE\ConnectBundle\Tests\Entity;

use AE\ConnectBundle\Connection\Dbal\SalesforceIdEntityInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class AltSalesforceId
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @ORM\Entity()
 * @ORM\Table(name="alt_salesforce_id")
 */
class AltSalesforceId implements SalesforceIdEntityInterface
{
    /**
     * @var int|null
     * @ORM\Column(type="integer", unique=true, nullable=false, options={"unsigned"=true})
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Id()
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(length=80, nullable=false)
     */
    private $connection;

    /**
     * @var string
     * @ORM\Column(length=18, nullable=false, unique=true)
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
     * @return AltSalesforceId
     */
    public function setId(?int $id): AltSalesforceId
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * @param string $connection
     *
     * @return AltSalesforceId
     */
    public function setConnection($connection): AltSalesforceId
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * @return string
     */
    public function getSalesforceId(): string
    {
        return $this->salesforceId;
    }

    /**
     * @param string $salesforceId
     *
     * @return AltSalesforceId
     */
    public function setSalesforceId(?string $salesforceId): AltSalesforceId
    {
        $this->salesforceId = $salesforceId;

        return $this;
    }
}
