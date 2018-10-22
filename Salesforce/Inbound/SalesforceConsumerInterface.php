<?php
/**
 * Created by PhpStorm.
 * User: alex.boyce
 * Date: 10/22/18
 * Time: 1:21 PM
 */

namespace AE\ConnectBundle\Salesforce\Inbound;

use AE\SalesforceRestSdk\Bayeux\ConsumerInterface;

interface SalesforceConsumerInterface extends ConsumerInterface
{
    public const CREATED = "CREATED";
    public const UPDATED = "UPDATED";
    public const DELETED = "DELETED";

    /**
     * Channels can be an explicit list of channel names, such as '/topic/Whatever' or an associative array of
     * topics, objects, platform_events, or generic_events.
     *
     * Wildcards are also supported. Any array value of '*' will be interpreted as "all". So, returning ['*']
     * will subscribe to all topics, objects, platform_events, and generic_events declared in the config for each
     * connection the consumer is assigned to.
     *
     * Using wildcards inside an array associated with a key will subscribe the consumer to all events of that type.
     * So in the example below, this consumer would be subscribed to all generic events from Salesforce.
     *
     * @code
     *      [
     *          'topics' => ['topicName'],
     *          'objects' => ['Account', 'Contact']
     *          'platform_events' => ['MyEvent__e'],
     *          'generic_events' => ['*']
     *      ]
     *
     * @return array
     */
    public function channels(): array;
}
