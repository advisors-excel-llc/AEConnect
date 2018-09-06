<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/6/18
 * Time: 12:19 PM
 */

namespace AE\ConnectBundle\Bayeux\Transport;

use AE\ConnectBundle\Bayeux\Message;
use JMS\Serializer\SerializerInterface;

class LongPollingTransport extends HttpClientTransport
{
    public function __construct(SerializerInterface $serializer)
    {
        parent::__construct('long-polling');
        $this->setSerializer($serializer);
    }

    public function abort()
    {
        // TODO: Implement abort() method.
    }

    /**
     * @param Message[]|array $messages
     * @param callable $callback
     *
     * @return mixed|void
     */
    public function send($messages, callable $callback)
    {
        // TODO: Implement send() method.
    }
}
