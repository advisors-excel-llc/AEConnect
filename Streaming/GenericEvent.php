<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/30/18
 * Time: 3:32 PM
 */

namespace AE\ConnectBundle\Streaming;

class GenericEvent extends AbstractSubscriber
{
    /**
     * @var string
     */
    private $name;

    public function __construct(string $name)
    {
        parent::__construct();
        $this->name = $name;
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
     * @return GenericEvent
     */
    public function setName(string $name): GenericEvent
    {
        $this->name = $name;

        return $this;
    }

    public function getChannelName(): string
    {
        return '/u/'.$this->name;
    }
}
