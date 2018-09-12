<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 6:10 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Bayeux\ConsumerInterface;

interface ClientInterface
{
    public function addTopic(TopicInterface $topic);
    public function subscribe(string $topicName, ConsumerInterface $consumer);
    public function start();
    public function stop();
}
