<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 11:43 AM
 */

namespace AE\ConnectBundle\Bayeux;

interface ChannelInterface
{
    public function notifyMessageListeners(Message $message);
}
