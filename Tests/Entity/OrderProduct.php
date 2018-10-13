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
 * Class OrderProduct
 *
 * @package AE\ConnectBundle\Tests\Entity
 * @SObjectType("OrderItem")
 * @ORM\Entity()
 * @ORM\Table("order_product")
 * @ORM\HasLifecycleCallbacks()
 */
class OrderProduct
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
     * @ORM\Column(length=80, nullable=true)
     * @Field(name="OrderItemNumber")
     */
    private $orderNumber;

    /**
     * @var Order
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Order")
     * @Field("OrderId")
     */
    private $order;

    /**
     * @var Product
     * @ORM\ManyToOne(targetEntity="AE\ConnectBundle\Tests\Entity\Product")
     * @Field("Product2Id")
     */
    private $product;

    /**
     * @var double|null
     * @ORM\Column(type="decimal", scale=16, precision=2, nullable=true)
     * @Field("UnitPrice")
     */
    private $unitPrice;

    /**
     * @var double|null
     * @ORM\Column(type="decimal", scale=16, precision=2, nullable=true)
     * @Field("TotalPrice")
     */
    private $totalPrice;

    /**
     * @var int|null
     * @ORM\Column(type="integer", nullable=true, options={"unsigned"=true})
     * @Field("Quantity")
     */
    private $quantity;

    /**
     * @var double
     * @ORM\Column(type="decimal", nullable=true)
     * @Field("ListPrice")
     */
    private $listPrice;

    /**
     * @var int|null
     * @ORM\Column(type="integer", nullable=true, options={"unsigned"=true})
     * @Field("AvailableQuantity")
     */
    private $availableQuantity;

    /**
     * @var string
     * @Field(value="hcid__c")
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
     * @return OrderProduct
     */
    public function setId(?int $id): OrderProduct
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    /**
     * @param null|string $orderNumber
     *
     * @return OrderProduct
     */
    public function setOrderNumber(?string $orderNumber): OrderProduct
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    /**
     * @return Order
     */
    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * @param Order $order
     *
     * @return OrderProduct
     */
    public function setOrder(Order $order): OrderProduct
    {
        $this->order = $order;

        return $this;
    }

    /**
     * @return Product
     */
    public function getProduct(): Product
    {
        return $this->product;
    }

    /**
     * @param Product $product
     *
     * @return OrderProduct
     */
    public function setProduct(Product $product): OrderProduct
    {
        $this->product = $product;

        return $this;
    }

    /**
     * @return float|null
     */
    public function getUnitPrice(): ?float
    {
        return $this->unitPrice;
    }

    /**
     * @param float|null $unitPrice
     *
     * @return OrderProduct
     */
    public function setUnitPrice(?float $unitPrice): OrderProduct
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @return float|null
     */
    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }

    /**
     * @param float|null $totalPrice
     *
     * @return OrderProduct
     */
    public function setTotalPrice(?float $totalPrice): OrderProduct
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    /**
     * @param int|null $quantity
     *
     * @return OrderProduct
     */
    public function setQuantity(?int $quantity): OrderProduct
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * @return float
     */
    public function getListPrice(): ?float
    {
        return $this->listPrice;
    }

    /**
     * @param float $listPrice
     *
     * @return OrderProduct
     */
    public function setListPrice(float $listPrice): OrderProduct
    {
        $this->listPrice = $listPrice;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getAvailableQuantity(): ?int
    {
        return $this->availableQuantity;
    }

    /**
     * @param int|null $availableQuantity
     *
     * @return OrderProduct
     */
    public function setAvailableQuantity(?int $availableQuantity): OrderProduct
    {
        $this->availableQuantity = $availableQuantity;

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
     * @return OrderProduct
     */
    public function setExtId(string $extId): OrderProduct
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
     * @return OrderProduct
     */
    public function setSfid(?string $sfid): OrderProduct
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
