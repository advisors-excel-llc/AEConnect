<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/30/18
 * Time: 2:56 PM
 */

namespace AE\ConnectBundle\Streaming;

class PlatformEvent extends AbstractSubscriber
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
     * @return PlatformEvent
     */
    public function setName(string $name): PlatformEvent
    {
        $this->name = $name;

        return $this;
    }

    public function getChannelName(): string
    {
        return '/event/'.$this->name;
    }
}
