<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 9/30/18
 * Time: 2:35 PM
 */

namespace AE\ConnectBundle\Streaming;

use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;

interface ChannelSubscriberInterface
{
    public function getName(): string;

    public function setName(string $name);

    public function addConsumer(ConsumerInterface $consumer);

    public function getConsumers(): array;

    public function getChannelName(): string;
}
