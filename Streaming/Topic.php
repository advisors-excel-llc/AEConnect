<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 5:36 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Bayeux\ConsumerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use JMS\Serializer\Annotation as JMS;

/**
 * Class Topic
 *
 * @package AE\ConnectBundle\Streaming
 * @JMS\ExclusionPolicy("NONE")
 */
class Topic implements TopicInterface
{
    /**
     * @var ArrayCollection|ConsumerInterface[]
     * @JMS\Exclude()
     */
    private $subscribers;

    /**
     * @var string
     * @JMS\Type("string")
     * @JMS\SerializedName("Name")
     */
    private $name;

    /**
     * @var string
     * @JMS\Type("string")
     * @JMS\SerializedName("Query")
     */
    private $query;

    /**
     * @var array
     * @JMS\Exclude()
     */
    private $filters = [];

    /**
     * @var string
     * @JMS\Type("string")
     * @JMS\SerializedName("ApiVersion")
     */
    private $apiVersion;

    /**
     * @var bool
     * @JMS\Type("bool")
     * @JMS\SerializedName("NotifyForOperationCreate")
     */
    private $notifyForOperationCreate;

    /**
     * @var bool
     * @JMS\Type("bool")
     * @JMS\SerializedName("NotifyForOperationUpdate")
     */
    private $notifyForOperationUpdate;

    /**
     * @var bool
     * @JMS\Type("bool")
     * @JMS\SerializedName("NotifyForOperationUndelete")
     */
    private $notifyForOperationUndelete;

    /**
     * @var bool
     * @JMS\Type("bool")
     * @JMS\SerializedName("NotifyForOperationDelete")
     */
    private $notifyForOperationDelete;

    /**
     * @var string
     * @JMS\Type("string")
     * @JMS\SerializedName("NotifyForFields")
     */
    private $notifyForFields;

    /**
     * @var bool
     * @JMS\Exclude()
     */
    private $autoCreate = true;

    public function __construct()
    {
        $this->subscribers = new ArrayCollection();
    }

    public function addSubscriber(ConsumerInterface $consumer)
    {
        if (!$this->subscribers->contains($consumer)) {
            $this->subscribers->add($consumer);
        }
    }

    /**
     * @return ConsumerInterface[]
     */
    public function getSubscribers(): array
    {
        return $this->subscribers->toArray();
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
     * @return Topic
     */
    public function setName(string $name): Topic
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @param string $query
     *
     * @return Topic
     */
    public function setQuery(string $query): Topic
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return array
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @param array $filters
     *
     * @return Topic
     */
    public function setFilters(array $filters): Topic
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @return string
     */
    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * @param string $apiVersion
     *
     * @return Topic
     */
    public function setApiVersion(string $apiVersion): Topic
    {
        $this->apiVersion = $apiVersion;

        return $this;
    }

    /**
     * @return bool
     */
    public function isNotifyForOperationCreate(): bool
    {
        return $this->notifyForOperationCreate;
    }

    /**
     * @param bool $notifyForOperationCreate
     *
     * @return Topic
     */
    public function setNotifyForOperationCreate(bool $notifyForOperationCreate): Topic
    {
        $this->notifyForOperationCreate = $notifyForOperationCreate;

        return $this;
    }

    /**
     * @return bool
     */
    public function isNotifyForOperationUpdate(): bool
    {
        return $this->notifyForOperationUpdate;
    }

    /**
     * @param bool $notifyForOperationUpdate
     *
     * @return Topic
     */
    public function setNotifyForOperationUpdate(bool $notifyForOperationUpdate): Topic
    {
        $this->notifyForOperationUpdate = $notifyForOperationUpdate;

        return $this;
    }

    /**
     * @return bool
     */
    public function isNotifyForOperationUndelete(): bool
    {
        return $this->notifyForOperationUndelete;
    }

    /**
     * @param bool $notifyForOperationUndelete
     *
     * @return Topic
     */
    public function setNotifyForOperationUndelete(bool $notifyForOperationUndelete): Topic
    {
        $this->notifyForOperationUndelete = $notifyForOperationUndelete;

        return $this;
    }

    /**
     * @return bool
     */
    public function isNotifyForOperationDelete(): bool
    {
        return $this->notifyForOperationDelete;
    }

    /**
     * @param bool $notifyForOperationDelete
     *
     * @return Topic
     */
    public function setNotifyForOperationDelete(bool $notifyForOperationDelete): Topic
    {
        $this->notifyForOperationDelete = $notifyForOperationDelete;

        return $this;
    }

    /**
     * @return string
     */
    public function getNotifyForFields(): string
    {
        return $this->notifyForFields;
    }

    /**
     * @param string $notifyForFields
     *
     * @return Topic
     */
    public function setNotifyForFields(string $notifyForFields): Topic
    {
        $this->notifyForFields = $notifyForFields;

        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoCreate(): bool
    {
        return $this->autoCreate;
    }

    /**
     * @param bool $autoCreate
     *
     * @return Topic
     */
    public function setAutoCreate(bool $autoCreate): Topic
    {
        $this->autoCreate = $autoCreate;

        return $this;
    }
}
