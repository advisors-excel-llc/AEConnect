<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/30/18
 * Time: 3:04 PM
 */

namespace AE\ConnectBundle\Streaming;

class ChangeEvent extends AbstractSubscriber
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
     * @return ChangeEvent
     */
    public function setName(string $name): ChangeEvent
    {
        $this->name = $name;

        return $this;
    }

    public function getChannelName(): string
    {
        $name = $this->name;

        if (preg_match('/__(c|C)$/', $name) == true) {
            $name = preg_replace('/__(c|C)$/', '__ChangeEvent', $name);
        } else {
            $name .= 'ChangeEvent';
        }

        return '/data/'.$name;
    }
}
