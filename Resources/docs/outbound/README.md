# Outbound Data to Salesforce

Any [mapped entities](../config/entity_mapping.md) that pass [validation](../validation/README.md) are sent to an 
Outbound Queue to be processed in the background via the Enqueue library.

Enqueue give AE Connect some flexibility in how outbound data to Salesforce is queued for processing. The Enqueue bundle
has its own configuration that AE Connect piggy backs off of.

> Currently, AE Connect is using Enqueue version 0.8 which only supports a single, default queue. Once 0.9 is released,
> AE Connect will be able to pass configuration to Enqueue for a dedicated queue to AE Connect that will be able to be
> configured via the Enqueue config.

Here's an example of how the Enqueue bundle can be configured to use a FileSystem driver.

```yaml
# config.yml (or enqueue.yaml if you're using symfony flex)

enqueue:
    transport:
        default: 'file://'
    client: ~
    # If you're looking to debug the consumers, a traceable_producer can be helpful
    # client:
    #     traceable_producer: true
    async_events: true # use true for production, false if you intend to manually run the consume command
```

Enqueue supports many transport types, such as Rabbit/AMQP, Kafka, DBAL, Google PubSub, Amazon SQS, Doctrine DBAL, Redis and more.

[Read more about Enqueue.](https://github.com/php-enqueue/enqueue-dev)

Once you have Enqueue configured, you're ready to start the consumer. If the consumer is not running, no outbound data
will be sent to Salesforce. The consume command also handles all connections in the `ae_connect` configuration; unlike
the inbound commands, no connection name argument is necessary.

> The consume command accepts an "idle window" option that tells the Outbound Queue how long to wait after receiving a
> message before it should send the data to Salesforce. The default is 10 seconds. This means that the Outbound Queue
> will wait 10 seconds for another message before sending the data. If another message is queued, the clock restarts.
> If the queue hits 1000 messages before the "idle window" is reached, the queue is flushed to Salesforce in order to
> keep memory usage down and to ensure timely updates to Salesforce.

```bash
$ bin/console ae_connect:consume # -w 10 or --wait 10 appended would wait 10 seconds from the last message
```

> Be aware that this command is blocking commands. It will not terminate until you terminate it or it crashes.
> If you terminate it, the consumer will stop and no new data will be queued or sent to Salesforce.
> This command should be run in the background via a service like `supervisord` or something like
> `$ nohup bin/console ae_connect:consume &>/dev/null &`. Just be aware that `&>/dev/null` will kill any console output.
> You may want to divert the output to a log file instead.