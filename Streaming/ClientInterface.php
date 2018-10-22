<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/12/18
 * Time: 6:10 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;

interface ClientInterface
{
    public function addSubscriber(ChannelSubscriberInterface $subscriber);
    public function subscribe(SalesforceConsumerInterface $consumer);
    public function start();
    public function stop();
    public function getClient();
}
