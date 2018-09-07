<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/7/18
 * Time: 5:45 PM
 */

namespace AE\ConnectBundle\Bayeux\Salesforce;

use JMS\Serializer\Annotation as JMS;

/**
 * Class StreamingData
 *
 * @package AE\ConnectBundle\Bayeux\Salesforce
 * @JMS\ExclusionPolicy("NONE")
 */
class StreamingData
{
    /**
     * @var Event
     * @JMS\Type("AE\ConnectBundle\Bayeux\Salesforce\Event")
     */
    private $event;

    /**
     * @var array|null
     * @JMS\Type("array")
     */
    private $sobject;

    /**
     * @var mixed
     * @JMS\Type("array")
     */
    private $payload;

    /**
     * @return Event
     */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * @param Event $event
     *
     * @return StreamingData
     */
    public function setEvent(Event $event): StreamingData
    {
        $this->event = $event;

        return $this;
    }

    /**
     * @return array|null
     */
    public function getSobject(): ?array
    {
        return $this->sobject;
    }

    /**
     * @param array|null $sobject
     *
     * @return StreamingData
     */
    public function setSobject(?array $sobject): StreamingData
    {
        $this->sobject = $sobject;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @param mixed $payload
     *
     * @return StreamingData
     */
    public function setPayload($payload)
    {
        $this->payload = $payload;

        return $this;
    }
}
