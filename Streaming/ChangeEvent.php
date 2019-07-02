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
    public const GAP_CREATE   = "GAP_CREATE";
    public const GAP_UPDATE   = "GAP_UPDATE";
    public const GAP_DELETE   = "GAP_DELETE";
    public const GAP_UNDELETE = "GAP_UNDELETE";
    public const GAP_OVERFLOW = "GAP_OVERFLOW";

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $appName;

    public function __construct(string $name, ?string $appName = null)
    {
        parent::__construct();
        $this->name    = $name;
        $this->appName = $appName;
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
        $name = preg_replace('/__(c|C)$/', '__', $this->name).'ChangeEvent';

        if (null !== $this->appName) {
            $name .= ";client=$this->appName";
        }

        return '/data/'.$name;
    }
}
