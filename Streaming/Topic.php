<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 5:36 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Consumer;
use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;
use AE\SalesforceRestSdk\Bayeux\Message;

/**
 * Class Topic
 *
 * @package AE\ConnectBundle\Streaming
 */
class Topic extends AbstractSubscriber
{

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $type;

    /**
     * @var array
     */
    private $filters = [];

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
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     *
     * @return Topic
     */
    public function setType(string $type): Topic
    {
        $this->type = $type;

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

    public function getChannelName(): string
    {
        $name = '/topic/'.$this->name;

        $filters = $this->filters;

        if (!empty($filters)) {
            array_walk($filters, function (&$value, $key) {
                $value = "$key=$value";
            });
            $name .= '?'.implode("&", $filters);
        }

        return $name;
    }

    public function addConsumer(ConsumerInterface $consumer)
    {
        $consumerWrapper = Consumer::create(function (ChannelInterface $channel, Message $message) use ($consumer) {
            $data = $message->getData();

            if (null !== $data) {
                $object = $data->getSobject();

                if (null !== $object) {
                    $object->Type = $this->type;
                }
            }

            $consumer->consume($channel, $message);
        });

        parent::addConsumer($consumerWrapper);
    }
}
