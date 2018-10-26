# Inbound Data from Salesforce

AE Connect receives data from Salesforce via the Streaming API in a number of ways: Push Topics, Platform Events,
Generic Events, or Change Events. AE Connect, itself, only really cares about one of those: Change Events. So what
about all those others? What's up with that?

Those are for you to worry with, my friend. You get to use that big ol' brain of yours to come up with some fancy
schmancy new things, What AE Connect does is makes it simple for you to wire up to these events.

## 3. 2. 1. Contact!

First off, let it be said that nothing will ever happen if the listener isn't running. And a listener must be running
for each connection!

```bash
$ bin/console ae_connect:listen  # this will start the default connection
$ bin/console ae_connect:listen other_connection # this will start the listener for a connection named "other_connection"
```

> Be aware that these commands are blocking commands. They will not terminate until you terminate them or they crash.
> If you terminate them, the listeners stop and no new events will be received by your consumers.
> These commands should be run in the background via a service like `supervisord` or something like
> `$ nohup bin/console ae_connect:listen &>/dev/null &`. Just be aware that `&>/dev/null` will kill any console output.
> You may want to divert the output to a log file instead.

## Consumer Beware!

Ok! Now that you know how to start this puppy up, let's do something fancy. Let's create a consumer to listen to some
Platform Events.

Pretend that we have a Platform Event name **MyEvent__e** and it has 2 fields:
* AccountId__c
* DogName__c

We can create a consumer that implements the `SalesforceConsumerInterface` and handle the data however we like.

```php
<?php

namespace App\Salesforce\Inbound;

use AE\ConnectBundle\Salesforce\Inbound\SalesforceConsumerInterface;
use AE\SalesforceRestSdk\Bayeux\ChannelInterface;
use AE\SalesforceRestSdk\Bayeux\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareTrait;

class MyEventConsumer implements SalesforceConsumerInterface {
    use LoggerAwareTrait;
    
    public function __construct(LoggerInterface $logger) {
        $this->setLogger($logger);
    }
    
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
     *          'generic_events' => ['*'],
     *           '/data/UserChangeEvent'
     *      ]
     *
     * @return array
     */
    public function channels(): array
    {
        return [
              'platform_events' => ['MyEvent__e']
            ];
    }
    
    public function consume(ChannelInterface $channel, Message $message)
    {
        // If you subscribe to multiple channels, you can determine which channel
        // the message was sent to using the $channel object
        
        // All data for all event messages is structured in a similar way
        // we can use `Message::getData()` to get the event
        $data = $message->getData();
        
        // You can get some more details about the event using $data->getEvent();
        $event = $data->getEvent();
        $createdDate = $event->getCreatedDate();
        $this->logger->info($createdDate->format(\DATE_ISO8601));
        
        
        // If we subscribing to a PushTopic, you could use $data->getSObject()
        // but for everything else, we use $data->getPayload();
        $payload = $data->getPayload();
        
        // The $payload is whatever. If it's a PlatformEvent, it's an array structured with the field names as keys
        // If it's a GenericEvent, the serializer will attempt to create an array from the data. But it might
        // end up being an array with a single string value that needs to be deserialized
        
        $this->logger->info(
            'The AccountId is {account}. The Dog\'s name is: {dog}',
            [
               'account' => $payload['AccountId__c'],
               'dog' => $payload['DogName__c']
            ]
        );
    }
    
    public function getPriority(): ?int
    {
        // You can set the priority if you wish, otherwise, return null
        return null;
    }
}

```

Now we just need to wire up our consumer to the container:

```yaml
# services.yml
services:
    App\Salesforce\Inbound\MyEventConsumer:
        autowire: true
        
    # you can also control which connections your consumer subscribes to using the tag
    App\Salesforce\Inbound\MyEventConsumer:
        autowire: true
        tags:
            - { name: 'ae_connect.consumer', connections: 'default,other_connection' }
```

Boom! Now your consumer will be automagically connected to Salesforce and will be fired when `MyEvent__e` is
dispatched.