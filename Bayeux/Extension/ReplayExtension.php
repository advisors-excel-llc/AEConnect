<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/11/18
 * Time: 12:06 PM
 */

namespace AE\ConnectBundle\Bayeux\Extension;

use AE\ConnectBundle\Bayeux\ChannelInterface;
use AE\ConnectBundle\Bayeux\Message;

class ReplayExtension implements ExtensionInterface
{
    public const REPLAY_NEWEST = -1;
    public const REPLAY_SAVED  = -2;
    public const NAME = 'replay';

    /**
     * @var int
     */
    private $replayId = self::REPLAY_NEWEST;

    public function __construct(int $replayId = self::REPLAY_NEWEST)
    {
        $this->replayId = $replayId;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * @param Message $message
     */
    public function prepareSend(Message $message): void
    {
        if ($message->getClientId() === ChannelInterface::META_CONNECT) {
            $ext                   = $message->getExt() ?: [];
            $ext[$this->getName()] = $this->replayId;

            $message->setExt($ext);
        }
    }

    /**
     * @param Message $message
     */
    public function processReceive(Message $message): void
    {
        if (!$message->isMeta()) {
            $data = $message->getData();

            if (null !== $data) {
                $event = $data->getEvent();

                if (null !== $event && null !== $event->getReplayId()) {
                    $this->replayId = $event->getReplayId();
                }
            }
        }
    }
}
